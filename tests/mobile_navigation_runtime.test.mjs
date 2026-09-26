import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('js/mobile-navigation.js', 'utf8');

function createHarness({withAdmin = true, mobile = true} = {}) {
  let documentRef = null;

  function makeClassList() {
    const values = new Set();
    return {
      add(...names) { names.forEach(name => values.add(name)); },
      remove(...names) { names.forEach(name => values.delete(name)); },
      contains(name) { return values.has(name); },
      toArray() { return [...values]; }
    };
  }

  function makeNode(name) {
    const listeners = {};
    const attributes = {};
    const node = {
      name,
      parentNode: null,
      children: [],
      hidden: false,
      open: false,
      classList: makeClassList(),
      appendChild(child) {
        if (child.parentNode) {
          const previous = child.parentNode.children.indexOf(child);
          if (previous >= 0) {
            child.parentNode.children.splice(previous, 1);
          }
        }
        child.parentNode = this;
        this.children.push(child);
        return child;
      },
      insertBefore(child, reference) {
        if (child.parentNode) {
          const previous = child.parentNode.children.indexOf(child);
          if (previous >= 0) {
            child.parentNode.children.splice(previous, 1);
          }
        }
        child.parentNode = this;
        const index = reference ? this.children.indexOf(reference) : -1;
        if (index < 0) {
          this.children.push(child);
        } else {
          this.children.splice(index, 0, child);
        }
        return child;
      },
      addEventListener(type, callback) {
        listeners[type] = callback;
      },
      dispatch(type, event = {}) {
        if (listeners[type]) {
          listeners[type](event);
        }
      },
      setAttribute(key, value) {
        attributes[key] = String(value);
      },
      getAttribute(key) {
        return Object.prototype.hasOwnProperty.call(attributes, key)
          ? attributes[key]
          : null;
      },
      focus() {
        documentRef.activeElement = this;
      }
    };

    Object.defineProperty(node, 'nextSibling', {
      get() {
        if (!this.parentNode) {
          return null;
        }
        const index = this.parentNode.children.indexOf(this);
        return this.parentNode.children[index + 1] || null;
      }
    });

    return node;
  }

  const root = makeNode('html');
  const headerActions = makeNode('header-actions');
  const profileMenu = makeNode('profile-menu');
  const profileSummary = makeNode('profile-summary');
  const profileBadge = makeNode('profile-badge');
  const cart = makeNode('cart');
  const admin = withAdmin ? makeNode('admin') : null;
  const bottomNavigation = makeNode('bottom-navigation');
  const toggle = makeNode('toggle');
  const profileAction = makeNode('profile-action');
  const cartSlot = makeNode('cart-slot');
  const adminSlot = withAdmin ? makeNode('admin-slot') : null;
  const menuLayer = makeNode('menu-layer');
  const menuSheet = makeNode('menu-sheet');
  const backdrop = makeNode('backdrop');
  const close = makeNode('close');

  profileMenu.appendChild(profileSummary);
  profileSummary.appendChild(profileBadge);
  headerActions.appendChild(profileMenu);
  headerActions.appendChild(cart);
  if (admin) {
    headerActions.appendChild(admin);
  }

  const selectorMap = new Map([
    ['[data-mobile-bottom-navigation]', bottomNavigation],
    ['[data-mobile-menu-toggle]', toggle],
    ['[data-mobile-profile-action]', profileAction],
    ['[data-mobile-cart-slot]', cartSlot],
    ['[data-mobile-admin-slot]', adminSlot],
    ['[data-mobile-menu-layer]', menuLayer],
    ['[data-mobile-menu-sheet]', menuSheet],
    ['[data-mobile-menu-backdrop]', backdrop],
    ['[data-mobile-menu-close]', close],
    ['.public-header-profile', profileMenu],
    ['.header-cart', cart],
    ['.public-header-admin-action', admin]
  ]);

  const documentListeners = {};
  const mediaListeners = {};
  const mediaQuery = {
    matches: mobile,
    addEventListener(type, callback) {
      mediaListeners[type] = callback;
    }
  };

  const document = {
    documentElement: root,
    activeElement: toggle,
    querySelector(selector) {
      return selectorMap.get(selector) || null;
    },
    getElementById(id) {
      return id === 'profile-notification-count' ? profileBadge : null;
    },
    createComment(label) {
      return makeNode('comment:' + label);
    },
    addEventListener(type, callback) {
      documentListeners[type] = callback;
    }
  };
  documentRef = document;

  const window = {
    matchMedia() {
      return mediaQuery;
    },
    requestAnimationFrame(callback) {
      callback();
    }
  };

  vm.runInContext(source, vm.createContext({
    window,
    document,
    console
  }));

  return {
    api: window.AnabelkaMobileNavigation,
    root,
    headerActions,
    profileMenu,
    profileSummary,
    profileBadge,
    cart,
    admin,
    bottomNavigation,
    profileAction,
    cartSlot,
    adminSlot,
    menuLayer,
    toggle,
    backdrop,
    close,
    mediaQuery,
    mediaListeners,
    documentListeners,
    document
  };
}

{
  const h = createHarness({withAdmin: true, mobile: true});

  assert.equal(h.api.initialized, true);
  assert.equal(h.profileBadge.parentNode, h.profileAction);
  assert.equal(h.cart.parentNode, h.cartSlot);
  assert.equal(h.admin.parentNode, h.adminSlot);
  assert.equal(h.root.classList.contains('has-mobile-navigation'), true);

  assert.equal(h.api.isOpen(), false);
  assert.equal(h.api.openMenu(), true);
  assert.equal(h.api.isOpen(), true);
  assert.equal(h.menuLayer.hidden, false);
  assert.equal(h.toggle.getAttribute('aria-expanded'), 'true');

  h.backdrop.dispatch('click');
  assert.equal(h.api.isOpen(), false);
  assert.equal(h.menuLayer.hidden, true);
  assert.equal(h.toggle.getAttribute('aria-expanded'), 'false');

  h.mediaQuery.matches = false;
  h.mediaListeners.change();

  assert.equal(h.cart.parentNode, h.headerActions);
  assert.equal(h.admin.parentNode, h.headerActions);
  assert.equal(h.profileBadge.parentNode, h.profileSummary);
}

{
  const h = createHarness({withAdmin: false, mobile: true});

  assert.equal(h.cart.parentNode, h.cartSlot);
  assert.equal(h.admin, null);
  assert.equal(h.adminSlot, null);
}

process.stdout.write('mobile navigation runtime passed\n');

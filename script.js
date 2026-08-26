let cartCount = 0;
const cartCountElement = document.getElementById("cartCount");
const toast = document.getElementById("toast");

document.querySelectorAll(".add-to-cart").forEach(button => {
    button.addEventListener("click", () => {
        cartCount++;
        cartCountElement.textContent = cartCount;

        toast.textContent = `${button.dataset.name} добавлен в корзину`;
        toast.classList.add("show");

        setTimeout(() => toast.classList.remove("show"), 1800);
    });
});

document.getElementById("cartButton").addEventListener("click", () => {
    if (cartCount === 0) {
        toast.textContent = "Корзина пока пуста";
    } else {
        toast.textContent = `В корзине товаров: ${cartCount}`;
    }
    toast.classList.add("show");
    setTimeout(() => toast.classList.remove("show"), 1800);
});

const toTop = document.getElementById("toTop");

window.addEventListener("scroll", () => {
    toTop.classList.toggle("show", window.scrollY > 500);
});

toTop.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: "smooth" });
});

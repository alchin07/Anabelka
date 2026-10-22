/**
 * Категорії емодзі для автономної роботи в Android KSWEB/Windows.
 * Unicode-символи беруть вигляд із системного emoji-шрифту пристрою.
 * Не завантажуємо сторонні трекери, CDN, набори Viber чи сторонні шрифти.
 */
(function () {
    'use strict';

    const categories = [
        {
            id: 'recent', label: 'Нещодавні', icon: '🕘', keywords: 'останні недавние recent',
            emoji: '😀 😂 😍 ❤️ 👍 🙏 🎉 💜 😊 🔥'
        },
        {
            id: 'faces', label: 'Смайлики та емоції', icon: '😀', keywords: 'лицо обличчя смайл улыбка сльози емоції грусть смех',
            emoji: [
                '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 🫠 😉 😊 😇 🥰 😍 🤩 😘 😗 ☺️ 😚 😙 🥲',
                '😋 😛 😜 🤪 😝 🤑 🤗 🤭 🫢 🫣 🤫 🤔 🫡 🤐 🤨 😐 😑 😶 🫥 😏 😒 🙄 😬',
                '😮‍💨 🤥 🫨 🙂‍↔️ 🙂‍↕️ 😌 😔 😪 🤤 😴 🥱 😷 🤒 🤕 🤢 🤮 🤧 🥵 🥶 🥴 😵 😵‍💫',
                '🤯 🤠 🥳 🥸 😎 🤓 🧐 😕 🫤 😟 🙁 ☹️ 😮 😯 😲 😳 🥺 🥹 😦 😧 😨 😰',
                '😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 ☠️ 💩 🤡 👹',
                '👺 👻 👽 👾 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾 🙈 🙉 🙊 💋 💌 💘 💝 💖',
                '💗 💓 💞 💕 💟 ❣️ 💔 ❤️‍🔥 ❤️‍🩹 ❤️ 🩷 🧡 💛 💚 💙 🩵 💜 🤎 🖤 🩶 🤍 💯',
                '💢 💥 💫 💦 💨 🕳️ 💬 👁️‍🗨️ 🗨️ 🗯️ 💭 💤'
            ].join(' ')
        },
        {
            id: 'people', label: 'Люди та жести', icon: '👋', keywords: 'руки люди пальцы привіт людина привет жест',
            emoji: [
                '👋 🤚 🖐️ ✋ 🖖 🫱 🫲 🫳 🫴 🫷 🫸 👌 🤌 🤏 ✌️ 🤞 🫰 🤟 🤘 🤙 👈 👉',
                '👆 🖕 👇 ☝️ 🫵 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 🫶 👐 🤲 🤝 🙏 ✍️ 💅 🤳 💪 🦾',
                '🦿 🦵 🦶 👂 🦻 👃 🧠 🫀 🫁 🦷 🦴 👀 👁️ 👅 👄 🫦 👶 🧒 👦 👧 🧑 👨 👩',
                '🧔 🧔‍♂️ 🧔‍♀️ 🧑‍🦰 👨‍🦰 👩‍🦰 🧑‍🦱 👨‍🦱 👩‍🦱 🧑‍🦳 👨‍🦳 👩‍🦳 🧑‍🦲 👨‍🦲 👩‍🦲',
                '👱 👱‍♂️ 👱‍♀️ 👴 👵 🧓 🙍 🙍‍♂️ 🙍‍♀️ 🙎 🙎‍♂️ 🙎‍♀️ 🙅 🙅‍♂️ 🙅‍♀️ 🙆 🙆‍♂️ 🙆‍♀️',
                '💁 💁‍♂️ 💁‍♀️ 🙋 🙋‍♂️ 🙋‍♀️ 🧏 🧏‍♂️ 🧏‍♀️ 🙇 🙇‍♂️ 🙇‍♀️ 🤦 🤦‍♂️ 🤦‍♀️ 🤷 🤷‍♂️ 🤷‍♀️',
                '👮 👮‍♀️ 👷 👷‍♀️ 💂 💂‍♀️ 🕵️ 🕵️‍♀️ 👩‍⚕️ 👨‍⚕️ 👩‍🎓 👨‍🎓 👩‍🏫 👨‍🏫 👩‍💻 👨‍💻',
                '👩‍🍳 👨‍🍳 👩‍🎨 👨‍🎨 👩‍✈️ 👨‍✈️ 👩‍🚀 👨‍🚀 👩‍🚒 👨‍🚒 🤰 🫃 🫄 🤱 👩‍🍼 👨‍🍼',
                '👼 🎅 🤶 🧑‍🎄 🦸 🦸‍♀️ 🦹 🧙 🧚 🧛 🧜 🧝 🧞 🧟 💃 🕺 🕴️ 👯',
                '🧖 🧖‍♀️ 🧘 🧘‍♀️ 🛀 🛌 🚶 🚶‍♀️ 🏃 🏃‍♀️ 🧎 🧎‍♀️ 🧍 🧍‍♀️',
                '👫 👬 👭 🧑‍🤝‍🧑 👩‍❤️‍👨 👩‍❤️‍👩 👨‍❤️‍👨 👩‍❤️‍💋‍👨 👨‍👩‍👧 👨‍👩‍👧‍👦 👩‍👧 👨‍👦'
            ].join(' ')
        },
        {
            id: 'nature', label: 'Тварини та природа', icon: '🐾', keywords: 'звери животные собаки кошки цветы рослини природа',
            emoji: [
                '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐻‍❄️ 🐨 🐯 🦁 🐮 🐷 🐽 🐸 🐵 🐒 🦍 🦧',
                '🐔 🐧 🐦 🐦‍⬛ 🐤 🐣 🐥 🦆 🦅 🦉 🦇 🐺 🐗 🐴 🦄 🫎 🫏 🐝 🪱 🐛 🦋',
                '🐌 🐞 🐜 🪰 🪲 🪳 🦟 🦗 🕷️ 🕸️ 🦂 🐢 🐍 🦎 🦖 🦕 🐙 🦑 🦐 🦞',
                '🦀 🪼 🐡 🐠 🐟 🐬 🐳 🐋 🦈 🦭 🐊 🐅 🐆 🦓 🫍 🦌 🦬 🐂 🐃 🐄',
                '🐖 🐏 🐑 🐐 🐪 🐫 🦙 🦒 🐘 🦣 🦏 🦛 🐁 🐀 🐇 🐿️ 🦫 🦔 🦨',
                '🦡 🦦 🦥 🐾 🦃 🐓 🦚 🦜 🦢 🦩 🕊️ 🐕 🦮 🐕‍🦺 🐩 🐈 🐈‍⬛ 🪶',
                '🌵 🎄 🌲 🌳 🌴 🪵 🌱 🌿 ☘️ 🍀 🎍 🪴 🎋 🍃 🍂 🍁 🍄 🍄‍🟫 🪺 🪹',
                '💐 🌷 🌹 🥀 🪻 🪷 🌺 🌸 🌼 🌻 🌞 🌝 🌛 🌜 🌚 🌕 🌖 🌗 🌘 🌑 🌒',
                '🌓 🌔 🌙 🌎 🌍 🌏 🪐 💫 ⭐ 🌟 ✨ ⚡ ☄️ 💥 🔥 🌪️ 🌈 ☀️ 🌤️',
                '⛅ 🌥️ ☁️ 🌦️ 🌧️ ⛈️ 🌩️ 🌨️ ❄️ ☃️ ⛄ 🌬️ 💨 💧 💦 ☔ 🌊 🫧'
            ].join(' ')
        },
        {
            id: 'food', label: 'Їжа та напої', icon: '🍕', keywords: 'їжа еда пища напитки овочі фрукты чай кофе',
            emoji: [
                '🍏 🍎 🍐 🍊 🍋 🍋‍🟩 🍌 🍉 🍇 🍓 🫐 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🍆',
                '🥑 🥦 🫛 🥬 🥒 🌶️ 🫑 🌽 🥕 🫒 🧄 🧅 🥔 🍠 🫘 🥜 🌰 🫚 🫜',
                '🍞 🥐 🥖 🫓 🥨 🥯 🥞 🧇 🧀 🍖 🍗 🥩 🥓 🍔 🍟 🍕 🌭 🥪 🌮 🌯',
                '🫔 🥙 🧆 🥚 🍳 🥘 🍲 🫕 🥣 🥗 🍿 🧈 🧂 🥫 🍱 🍘 🍙 🍚 🍛 🍜',
                '🍝 🍡 🍢 🍣 🍤 🍥 🥮 🍨 🍧 🍦 🥧 🧁 🍰 🎂 🍮 🍭 🍬 🍫 🍩 🍪',
                '🍯 🍼 🥛 ☕ 🫖 🍵 🍶 🍾 🍷 🍸 🍹 🍺 🍻 🥂 🥃 🫗 🥤 🧋 🧃 🧉',
                '🧊 🥢 🍽️ 🍴 🥄 🔪 🫙'
            ].join(' ')
        },
        {
            id: 'travel', label: 'Подорожі та місця', icon: '🚗', keywords: 'машина транспорт самолёт дорога город будинки поезд карта',
            emoji: [
                '🚗 🚕 🚙 🚌 🚎 🏎️ 🚓 🚑 🚒 🚐 🛻 🚚 🚛 🚜 🦽 🦼 🛴 🚲 🛵 🏍️',
                '🛺 🚨 🚔 🚍 🚘 🚖 🚡 🚠 🚟 🚃 🚋 🚞 🚝 🚄 🚅 🚈 🚂 🚆 🚇 🚊',
                '🚉 ✈️ 🛫 🛬 🛩️ 💺 🚀 🛸 🚁 🛶 ⛵ 🚤 🛥️ 🛳️ ⛴️ 🚢 ⚓ 🛟',
                '⛽ 🚧 🚦 🚥 🚏 🗺️ 🗿 🗽 🗼 🏰 🏯 🏟️ 🎡 🎢 🎠 ⛲ ⛱️ 🏖️ 🏝️',
                '🏜️ 🌋 ⛰️ 🏔️ 🗻 🏕️ ⛺ 🛖 🏠 🏡 🏘️ 🏚️ 🏗️ 🏭 🏢 🏬 🏣',
                '🏤 🏥 🏦 🏨 🏪 🏫 🏩 💒 🏛️ ⛪ 🕌 🕍 🛕 🕋 ⛩️ 🌁 🌃',
                '🏙️ 🌄 🌅 🌆 🌇 🌉 ♨️ 🎑 🌌 🌠 🎆 🎇 🌐 🌍 🌎 🌏'
            ].join(' ')
        },
        {
            id: 'activities', label: 'Розваги та спорт', icon: '⚽', keywords: 'спорт игры гра музика свято подарунок',
            emoji: [
                '🎃 🎄 🎆 🎇 🧨 ✨ 🎈 🎉 🎊 🎋 🎍 🎎 🎏 🎐 🎀 🎁 🎗️ 🎟️ 🎫',
                '🎖️ 🏆 🏅 🥇 🥈 🥉 ⚽ ⚾ 🥎 🏀 🏐 🏈 🏉 🎾 🥏 🎳 🏏 🏑',
                '🏒 🥍 🏓 🏸 🥊 🥋 🥅 ⛳ ⛸️ 🎣 🤿 🎽 🎿 🛷 🥌 🎯 🪀 🪁',
                '🔫 🎱 🔮 🪄 🎮 🕹️ 🎰 🎲 🧩 🧸 🪅 🪩 🪆 ♟️ 🃏 🀄 🎴 🎭 🎨',
                '🧵 🪡 🧶 🪢 🎼 🎵 🎶 🎙️ 🎚️ 🎛️ 🎤 🎧 📻 🎷 🪗 🎸 🎹 🎺 🎻',
                '🪕 🥁 🪘 🪇 🪈 🎬 🎪 🎟️'
            ].join(' ')
        },
        {
            id: 'objects', label: 'Предмети', icon: '💡', keywords: 'речі вещи инструменты техника телефон работа подарки',
            emoji: [
                '👓 🕶️ 🥽 🥼 🦺 👔 👕 👖 🧣 🧤 🧥 🧦 👗 👘 🥻 🩱 🩲 🩳 👙 👚',
                '🪭 👛 👜 👝 🛍️ 🎒 🩴 👞 👟 🥾 🥿 👠 👡 🩰 👢 👑 👒 🎩 🎓 🧢',
                '🪖 ⛑️ 📿 💄 💍 💎 🔇 🔈 🔉 🔊 📢 📣 📯 🔔 🔕 🎵 🎙️ 🎤 🎧',
                '📱 📲 ☎️ 📞 📟 📠 🔋 🪫 🔌 💻 🖥️ 🖨️ ⌨️ 🖱️ 🖲️ 💽 💾 💿 📀',
                '🧮 🎥 🎞️ 📽️ 📺 📷 📸 📹 📼 🔍 🔎 🕯️ 💡 🔦 🏮 🪔 📔 📕',
                '📖 📗 📘 📙 📚 📓 📒 📃 📜 📄 📰 🗞️ 📑 🔖 🏷️ 💰 🪙 💴 💵',
                '💶 💷 💸 💳 🧾 💹 ✉️ 📧 📨 📩 📤 📥 📦 📫 📪 📬 📭 📮',
                '🗳️ ✏️ ✒️ 🖋️ 🖊️ 🖌️ 🖍️ 📝 💼 📁 📂 🗂️ 📅 📆 🗒️ 🗓️ 📇',
                '📈 📉 📊 📋 📌 📍 📎 🖇️ 📏 📐 ✂️ 🗃️ 🗄️ 🗑️ 🔒 🔓 🔏 🔐 🔑',
                '🗝️ 🔨 🪓 ⛏️ ⚒️ 🛠️ 🗡️ ⚔️ 💣 🪃 🏹 🛡️ 🪚 🔧 🪛 🔩 ⚙️ 🗜️',
                '⚖️ 🦯 🔗 ⛓️ 🪝 🧰 🧲 🪜 ⚗️ 🧪 🧫 🧬 🔬 🔭 📡 💉 🩸 💊 🩹',
                '🩺 🩻 🚪 🛗 🪞 🪟 🛏️ 🛋️ 🪑 🚽 🪠 🚿 🛁 🪤 🪒 🧴 🧷 🧹',
                '🧺 🧻 🪣 🧼 🫧 🪥 🧽 🧯 🛒 🚬 ⚰️ 🪦 ⚱️ 🗿 🪧 🪪'
            ].join(' ')
        },
        {
            id: 'symbols', label: 'Символи', icon: '✅', keywords: 'символ знак сердечко серце указатель стрелка запрет',
            emoji: [
                '🏧 🚮 🚰 ♿ 🚹 🚺 🚻 🚼 🚾 🛂 🛃 🛄 🛅 ⚠️ 🚸 ⛔ 🚫 🚳 🚭',
                '🚯 🚱 🚷 📵 🔞 ☢️ ☣️ ⬆️ ↗️ ➡️ ↘️ ⬇️ ↙️ ⬅️ ↖️ ↕️ ↔️ ↩️ ↪️',
                '⤴️ ⤵️ 🔃 🔄 🔙 🔚 🔛 🔜 🔝 🛐 ⚛️ 🕉️ ✡️ ☸️ ☯️ ✝️ ☦️ ☪️',
                '☮️ 🕎 🔯 🪯 ♈ ♉ ♊ ♋ ♌ ♍ ♎ ♏ ♐ ♑ ♒ ♓ ⛎ 🔀 🔁 🔂',
                '▶️ ⏩ ⏭️ ⏯️ ◀️ ⏪ ⏮️ 🔼 ⏫ 🔽 ⏬ ⏸️ ⏹️ ⏺️ ⏏️ 🎦 🔅 🔆',
                '📶 🛜 📳 📴 ♀️ ♂️ ⚧️ ✖️ ➕ ➖ ➗ 🟰 ♾️ ‼️ ⁉️ ❓ ❔ ❕ ❗',
                '〰️ 💱 💲 ⚕️ ♻️ ⚜️ 🔱 📛 🔰 ⭕ ✅ ☑️ ✔️ ❌ ❎ ➰ ➿ 〽️',
                '✳️ ✴️ ❇️ ©️ ®️ ™️ #️⃣ *️⃣ 0️⃣ 1️⃣ 2️⃣ 3️⃣ 4️⃣ 5️⃣ 6️⃣ 7️⃣ 8️⃣ 9️⃣ 🔟',
                '🔠 🔡 🔢 🔣 🔤 🅰️ 🆎 🅱️ 🆑 🆒 🆓 ℹ️ 🆔 Ⓜ️ 🆕 🆖 🅾️ 🆗 🅿️',
                '🆘 🆙 🆚 🈁 🈂️ 🈷️ 🈶 🈯 🉐 🈹 🈚 🈲 🉑 🈸 🈴 🈳 ㊗️ ㊙️',
                '🈺 🈵 🔴 🟠 🟡 🟢 🔵 🟣 🟤 ⚫ ⚪ 🟥 🟧 🟨 🟩 🟦 🟪',
                '🟫 ⬛ ⬜ ◼️ ◻️ ◾ ◽ ▪️ ▫️ 🔶 🔷 🔸 🔹 🔺 🔻 💠 🔘 🔳 🔲',
                '❤️ 🩷 🧡 💛 💚 💙 🩵 💜 🤎 🖤 🩶 🤍 💔 ❣️ 💕 💞 💓 💗 💖 💘'
            ].join(' ')
        },
        {
            id: 'flags', label: 'Прапори', icon: '🏳️', keywords: 'флаги прапори країни країна страна',
            emoji: [
                '🏁 🚩 🎌 🏴 🏳️ 🏳️‍🌈 🏳️‍⚧️ 🏴‍☠️ 🇺🇦 🇩🇪 🇪🇺 🇧🇪 🇵🇱 🇫🇷 🇮🇹 🇪🇸 🇬🇧',
                '🇺🇸 🇨🇦 🇦🇺 🇳🇿 🇯🇵 🇰🇷 🇨🇳 🇹🇼 🇮🇳 🇧🇷 🇦🇷 🇲🇽 🇨🇱 🇵🇪 🇨🇴',
                '🇦🇹 🇨🇭 🇳🇱 🇱🇺 🇩🇰 🇸🇪 🇳🇴 🇫🇮 🇮🇸 🇮🇪 🇵🇹 🇬🇷 🇹🇷 🇨🇿 🇸🇰',
                '🇭🇺 🇷🇴 🇧🇬 🇭🇷 🇸🇮 🇷🇸 🇲🇪 🇲🇰 🇦🇱 🇧🇦 🇽🇰 🇲🇩 🇬🇪 🇦🇲 🇦🇿',
                '🇱🇹 🇱🇻 🇪🇪 🇧🇾 🇷🇺 🇰🇿 🇺🇿 🇹🇲 🇰🇬 🇹🇯 🇮🇱 🇸🇦 🇦🇪 🇶🇦',
                '🇪🇬 🇲🇦 🇹🇳 🇿🇦 🇳🇬 🇰🇪 🇪🇹 🇹🇿 🇬🇭 🇨🇲 🇸🇳 🇨🇮 🇲🇬 🇲🇺',
                '🇸🇬 🇲🇾 🇮🇩 🇵🇭 🇹🇭 🇻🇳 🇰🇭 🇱🇦 🇲🇲 🇧🇩 🇳🇵 🇵🇰 🇱🇰 🇲🇳'
            ].join(' ')
        }
    ];

    const names = {
        '😀':'посмішка улыбка smile', '😂':'сміх смех сльози слезы laugh',
        '🤣':'регіт смех весело', '😍':'кохання любовь сердечки love',
        '😘':'цілунок поцелуй kiss', '🥰':'закоханий любовь',
        '😭':'плач слёзы грусть', '😢':'плач грусть сум',
        '😡':'злість злой angry', '😎':'крутий очки cool',
        '🥳':'свято день рождения праздник', '👍':'палець вгору лайк ok',
        '👎':'не подобається дизлайк', '👏':'оплески аплодисменты',
        '🙏':'дякую спасибо молитва', '❤️':'червоне серце любовь',
        '💜':'фіолетове серце анабелька', '💔':'розбите серце',
        '🔥':'вогонь огонь fire', '🎉':'конфетті свято',
        '🎂':'торт день рождения', '🎁':'подарунок подарок',
        '🐱':'кіт кот', '🐶':'пес собака', '🐼':'панда',
        '🌹':'троянда роза', '🌷':'тюльпан', '🍓':'полуниця клубника',
        '🍕':'піца пицца', '☕':'кава кофе', '🍷':'вино',
        '✈️':'літак самолёт', '🚗':'авто машина', '🏠':'дім дом',
        '⚽':'футбол', '🎮':'відеоігри игры', '🎵':'музика музыка',
        '📱':'телефон', '💻':'ноутбук компьютер', '📷':'фото камера',
        '📎':'скріпка файл вложение', '📁':'папка', '📄':'документ',
        '💰':'гроші деньги', '✅':'галочка так готово',
        '❌':'хрестик крест нет', '❓':'питання вопрос',
        '🔞':'дорослі 18 плюс', '🇺🇦':'україна украина',
        '🇩🇪':'німеччина германия', '🇪🇺':'євросоюз европа'
    };

    categories.forEach(function (item) {
        item.items = Array.from(new Set(item.emoji.trim().split(/\s+/u)));
        delete item.emoji;
    });

    window.AnabelkaChatEmojiData = Object.freeze({ categories: categories, names: names });
}());


/* Індекси назв для опційного набору графічних смайлів, зразок із відео.
 * Це тільки словник назв: жодне стороннє зображення не входить у репозиторій.
 * Файли можуть бути встановлені виключно за наявності дозволу правовласника.
 * За відсутності файлів стандартні Unicode-емодзі працюють без змін.
 */
(function () {
    'use strict';
    const files = {"smiley":"smiley.png","sad":"sad.png","wink":"wink.png","angry":"angry.png","inlove":"inlove.png","yummi":"yummi.png","laugh":"laugh.png","surprised":"surprised.png","moa":"moa.png","happy":"happy.png","cry":"cry.png","sick":"sick.png","shy":"shy.png","teeth":"teeth.png","tongue":"tongue.png","money":"money.png","mad":"mad.png","flirt":"flirt.png","crazy":"crazy.png","confused":"confused.png","depressed":"depressed.png","scream":"scream.png","nerd":"nerd.png","not_sure":"not_sure.png","cool":"cool.png","huh":"huh.png","happycry":"happycry.png","mwah":"mwah.png","exhausted":"exhausted.png","eek":"eek.png","dizzy":"dizzy.png","dead":"dead.png","straight":"straight.png","yo":"yo.png","wtf":"wtf.png","ohno":"ohno.png","oh":"oh.png","wink2":"wink2.png","what":"what.png","weak":"weak.png","upset":"upset.png","ugh":"ugh.png","teary":"teary.png","singing":"singing.png","silly":"silly.png","meh":"meh.png","mischievous":"mischievous.png","ninja":"ninja.png","spiderman":"spiderman.png","batman":"batman.png","devil":"devil.png","angel":"angel.png","heart":"heart.png","heart_break":"heart_break.png","purple_heart":"purple_heart.png","unlike":"unlike.png","like":"like.png","V":"V.png","fu":"fu.png","clap":"clap.png","rockon":"rockon.png","angrymark":"angrymark.png","thinking":"thinking.png","zzz":"zzz.png","!":"exclamation.png","Q":"Q.png","diamond":"diamond.png","trophy":"trophy.png","crown":"crown.png","ring":"ring.png","$":"dollars.png","hammer":"hammer.png","wrench":"wrench.png","key":"key.png","lock":"lock.png","video":"video.png","TV":"TV.png","tape":"tape.png","trumpet":"trumpet.png","guitar":"guitar.png","speaker":"speaker.png","music":"music.png","microphone":"microphone.png","bell":"bell.png","koala":"koala.png","sheep":"sheep.png","ladybug":"ladybug.png","kangaroo":"kangaroo.png","chick":"chick.png","monkey":"monkey.png","panda":"panda.png","turtle":"turtle.png","bunny":"bunny.png","dragonfly":"dragonfly.png","fly":"fly.png","bee":"bee.png","bat":"bat.png","cat":"cat.png","dog":"dog.png","squirrel":"squirrel.png","snake":"snake.png","snail":"snail.png","goldfish":"goldfish.png","shark":"shark.png","pig":"pig.png","owl":"owl.png","penguin":"penguin.png","paw":"paw.png","poo":"poo.png","cap":"cap.png","fidora":"fidora.png","partyhat":"partyhat.png","cactus":"cactus.png","clover":"clover.png","sprout":"sprout.png","palmtree":"palmtree.png","christmas_tree":"christmas_tree.png","mapleleaf":"mapleleaf.png","flower":"flower.png","sunflower":"sunflower.png","sun":"sun.png","moon":"moon.png","rain":"rain.png","cloud":"cloud.png","umbrella":"umbrella.png","snowman":"snowman.png","snowflake":"snowflake.png","relax":"relax.png","flipflop":"flipflop.png","telephone":"telephone.png","phone":"phone.png","nobattery":"nobattery.png","battery":"battery.png","time":"time.png","knife":"knife.png","syringe":"syringe.png","termometer":"termometer.png","meds":"meds.png","ruler":"ruler.png","scissor":"scissor.png","paperclip":"paperclip.png","pencil":"pencil.png","magnify":"magnify.png","glasses":"glasses.png","book":"book.png","letter":"letter.png","weight":"weight.png","muscle":"muscle.png","boxing":"boxing.png","light_bulb":"light_bulb.png","lantern":"lantern.png","fire":"fire.png","torch":"torch.png","bomb":"bomb.png","cigarette":"cigarette.png","kiss":"kiss.png","gift":"gift.png","skull":"skull.png","ghost":"ghost.png","golf":"golf.png","golfball":"golfball.png","football":"football.png","tennis":"tennis.png","soccer":"soccer.png","basketball":"basketball.png","baseball":"baseball.png","8ball":"8ball.png","beachball":"beachball.png","balloon1":"balloon1.png","balloon2":"balloon2.png","cards":"cards.png","dice":"dice.png","console":"console.png","chicken":"chicken.png","burger":"burger.png","pizza":"pizza.png","noodles":"noodles.png","sushi1":"sushi1.png","sushi2":"sushi2.png","donut":"donut.png","egg":"egg.png","hotdog":"hotdog.png","ice_cream":"ice_cream.png","popsicle":"popsicle.png","cupcake":"cupcake.png","croissant":"croissant.png","chocolate":"chocolate.png","lollipop":"lollipop.png","popcorn":"popcorn.png","cake":"cake.png","cherry":"cherry.png","banana":"banana.png","watermelon":"watermelon.png","strawberry":"strawberry.png","grapes":"grapes.png","pineapple":"pineapple.png","pea":"pea.png","corn":"corn.png","mushroom":"mushroom.png","beer":"beer.png","wine":"wine.png","martini":"martini.png","coffee":"coffee.png","soda":"soda.png","car":"car.png","taxi":"taxi.png","ambulance":"ambulance.png","policecar":"policecar.png","bicycle":"bicycle.png","airplane":"airplane.png","ufo":"ufo.png","rocket":"rocket.png","run":"run.png"};
    const labels = {"smiley":"Посмішка","sad":"Сум","wink":"Підморгування","angry":"Злість","inlove":"Закоханий","yummi":"Смачно","laugh":"Сміх","surprised":"Здивування","moa":"Поцілунок","happy":"Радість","cry":"Плач","sick":"Хворий","shy":"Сором","teeth":"Зуби","tongue":"Язик","money":"Гроші","mad":"Роздратування","flirt":"Флірт","crazy":"Божевільний","confused":"Розгубленість","depressed":"Сумний","scream":"Крик","nerd":"Розумник","cool":"Крутий","happycry":"Сміх до сліз","dizzy":"Запаморочення","dead":"Непритомність","angel":"Ангел","devil":"Чорт","ninja":"Ніндзя","spiderman":"Маска супергероя","batman":"Кажан-супергерой","heart":"Серце","heart_break":"Розбите серце","purple_heart":"Фіолетове серце","unlike":"Не подобається","like":"Подобається","clap":"Оплески","thinking":"Думає","paw":"Лапка","strawberry":"Полуниця","pizza":"Піца","car":"Машина","rocket":"Ракета","soccer":"Футбол","wine":"Вино","coffee":"Кава","cake":"Торт","moon":"Місяць","flower":"Квітка","cherry":"Вишня","sun":"Сонце"};
    const categories = [
        {id: 'classic', label: 'Ілюстровані', icon: '💜',
            keywords: 'класичні графічні намальовані смайлики', codes: Object.keys(files)},
        ...[{"id":"classic-faces","label":"Обличчя","icon":"😀","keywords":"посмішка улыбка емоції лицa смех лица","codes":["smiley","sad","wink","angry","inlove","yummi","laugh","surprised","moa","happy","cry","sick","shy","teeth","tongue","money","mad","flirt","crazy","confused","depressed","scream","nerd","not_sure","cool","huh","happycry","mwah","exhausted","eek","dizzy","dead","straight","yo","wtf","ohno","oh","wink2","what","weak","upset","ugh","teary","singing","silly","meh","mischievous","ninja","spiderman","batman","devil","angel"]},{"id":"classic-gestures","label":"Серця та жести","icon":"❤️","keywords":"руки жест серце сердце love","codes":["heart","heart_break","purple_heart","unlike","like","V","fu","clap","rockon","angrymark","thinking","zzz","!","Q","diamond","trophy","crown","ring","$"]},{"id":"classic-music","label":"Музика й речі","icon":"🎵","keywords":"музика музыка вещи предмети","codes":["hammer","wrench","key","lock","video","TV","tape","trumpet","guitar","speaker","music","microphone","bell"]},{"id":"classic-animals","label":"Тварини","icon":"🐾","keywords":"лапки звірі животные природа","codes":["koala","sheep","ladybug","kangaroo","chick","monkey","panda","turtle","bunny","dragonfly","fly","bee","bat","cat","dog","squirrel","snake","snail","goldfish","shark","pig","owl","penguin","paw","poo"]},{"id":"classic-nature","label":"Природа","icon":"🌸","keywords":"рослини природа квіти цветы","codes":["cap","fidora","partyhat","cactus","clover","sprout","palmtree","christmas_tree","mapleleaf","flower","sunflower","sun","moon","rain","cloud","umbrella","snowman","snowflake"]},{"id":"classic-objects","label":"Предмети","icon":"💡","keywords":"предмети вещи техника фото","codes":["relax","flipflop","telephone","phone","nobattery","battery","time","knife","syringe","termometer","meds","ruler","scissor","paperclip","pencil","magnify","glasses","book","letter","weight","muscle","boxing","light_bulb","lantern","fire","torch","bomb","cigarette","kiss","gift","skull","ghost"]},{"id":"classic-sports","label":"Спорт та ігри","icon":"⚽","keywords":"спорт мяч игры","codes":["golf","golfball","football","tennis","soccer","basketball","baseball","8ball","beachball","balloon1","balloon2","cards","dice","console"]},{"id":"classic-food","label":"Їжа та напої","icon":"🍕","keywords":"еда їжа напитки фрукты","codes":["chicken","burger","pizza","noodles","sushi1","sushi2","donut","egg","hotdog","ice_cream","popsicle","cupcake","croissant","chocolate","lollipop","popcorn","cake","cherry","banana","watermelon","strawberry","grapes","pineapple","pea","corn","mushroom","beer","wine","martini","coffee","soda"]},{"id":"classic-travel","label":"Транспорт","icon":"🚗","keywords":"дорога самолёт машинa транспорт","codes":["car","taxi","ambulance","policecar","bicycle","airplane","ufo","rocket","run"]}]
    ];
    window.AnabelkaChatClassicData = Object.freeze({
        files: Object.freeze(files),
        labels: Object.freeze(labels),
        categories: Object.freeze(categories)
    });
}());

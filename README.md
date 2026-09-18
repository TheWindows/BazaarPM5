# BazaarPM5

**BazaarPM5** is a PocketMine-MP plugin for API 5 that allows players to buy and sell items through a custom GUI-based marketplace.

---

## 📦 Plugin Info

- **Name:** BazaarPM5  
- **Version:** 1.1.0  
- **Author:** TheWindows  
- **Main Class:** `TheWindows\Bazaar\Main`  
- **API:** 5.0.0  
- **Dependencies:** 
  - [EconomyAPI](https://poggit.pmmp.io/p/EconomyAPI)  
  - [InvMenu](https://poggit.pmmp.io/p/InvMenu)  
  - [FormAPI](https://poggit.pmmp.io/p/FormAPI)  

---

## ⚡ Features

- Fully GUI-based bazaar using InvMenu.
- Supports custom forms with FormAPI.
- Integration with EconomyAPI for player balances.
- Sell and buy items using in-game currency.
- Supports item preview.
- Random price fluctuation within configurable min/max bounds.
- Custom items with names, lore, enchantments and unbreakable flag.
- Works with PocketMine-MP API 5.

---

## 🛠 Commands

| Command                  | Description                              | Permission           |
|--------------------------|------------------------------------------|--------------------|
| `/bazaar`                | Opens the bazaar menu                    | `winshop.command`   |
| `/bazaar updateprices`   | Forces one random price fluctuation round | `winshop.admin`     |
| `/bazaar resetprices`    | Resets all prices back to config values  | `winshop.admin`     |
| `/bazaar reload`         | Reloads config and shop catalogue        | `winshop.admin`     |
| `/bazaar help`           | Shows bazaar command help                | `winshop.command`   |

---

## 📝 Permissions

| Permission         | Description                                        | Default |
|-------------------|----------------------------------------------------|---------|
| `winshop.command`  | Allows access to bazaar command                   | true    |
| `winshop.admin`    | Allows price updates, resets and config reloads   | op      |

---

## 🎲 Random Price Change

Configure in `config.yml` under `price-auto-update`:

```yaml
price-auto-update:
  enabled: true
  interval-minutes: 30
  max-fluctuation: 0.1  # 10% per round
  announce: true
```

- Every interval, each buy price moves randomly within `±max-fluctuation`
  and is clamped to its `[min_price, max_price]` bounds.
- Sell prices scale with buy prices so the buy/sell ratio is preserved.
- Active prices survive restarts (only new bounds/categories are synced);
  use `/bazaar resetprices` to force everything back to config values.

---

## ✨ Custom Items

Add entries under top-level `custom-items` (or extend any `items.<category>.<id>`
with the same extra keys):

```yaml
custom-items:
  god_sword:
    item: "diamond_sword"
    category: "tools"
    buy: 5000
    sell: 425
    min_price: 4000
    max_price: 6000
    custom-name: "§cGod Sword"
    lore:
      - "§7A legendary blade"
    enchantments:
      sharpness: 5
      fire_aspect: 2
    unbreakable: false
```

- `item` is the vanilla base id; the config key (`god_sword`) must be unique.
- `category` is one of `blocks/tools/food/misc` (unknown values fall back to `misc`).
- `enchantments` accepts a map (`{sharpness: 5}`) or a list (`["sharpness:5"]`).
- Buying gives the exact custom NBT; selling only accepts identical custom items.

---

## 💻 Installation

1. Download **BazaarPM5** and place it in your `plugins` folder.  
2. Make sure you have **EconomyAPI**, **InvMenu**, and **FormAPI** installed.  
3. Start your server to generate the configuration files.  
4. Use `/bazaar` in-game to open the bazaar menu.

---

## 📋 To-Do

- [x] Add MySQL support for data storage.
- [x] Add price changing functionality in config.
- [x] Add message customization options.
- [x] Add more items to the bazaar.
- [x] Add Random Price Change.
- [x] Add Custom Items.

---

## ⚠ Notes

- All players with `winshop.command` permission can access the bazaar.
- Make sure your EconomyAPI balance is sufficient before buying items.
- Plugin requires PocketMine-MP API 5.

---

## 🔗 Links

- [PocketMine-MP](https://www.pocketmine.net/)  
- [EconomyAPI](https://poggit.pmmp.io/p/EconomyAPI)  
- [InvMenu](https://poggit.pmmp.io/p/InvMenu)  
- [FormAPI](https://poggit.pmmp.io/p/FormAPI)  

---

Created with ❤️ by **TheWindows**

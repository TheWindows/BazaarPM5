# BazaarPM5

**BazaarPM5** is a PocketMine-MP plugin for API 5 that allows players to buy, sell, and trade items through a custom GUI-based marketplace.

---

## 📦 Plugin Info

- **Name:** BazaarPM5  
- **Version:** 1.0.0  
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
- Add enchantments to items for sale.
- Supports item preview and custom pricing.
- Works with PocketMine-MP API 5.

---

## 🛠 Commands

| Command       | Description                          | Permission           |
|---------------|--------------------------------------|--------------------|
| `/bazaar`     | Opens the bazaar menu                 | `winshop.command`   |
| `/sell <item>`| Sell an item through the bazaar GUI  | `winshop.command`   |
| `/buy <item>` | Buy an item from the bazaar           | `winshop.command`   |

---

## 📝 Permissions

| Permission         | Description                     | Default |
|-------------------|---------------------------------|---------|
| `winshop.command`  | Allows access to bazaar command | true    |

---

## 💻 Installation

1. Download **BazaarPM5** and place it in your `plugins` folder.  
2. Make sure you have **EconomyAPI**, **InvMenu**, and **FormAPI** installed.  
3. Start your server to generate the configuration files.  
4. Use `/bazaar` in-game to open the bazaar menu.

---

## ⚠ Notes

- Only players with `winshop.command` permission can access the bazaar.
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

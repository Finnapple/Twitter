# 💬 Chatlify

> **Real-time chat. Zero setup. Works everywhere.**

Chatlify is a modern, lightweight real-time chat application built with **PHP** and **vanilla JavaScript** — no database, no frameworks, no headaches. Whether you're on a local network or hosting it on the web, Chatlify just works.

---

## ✨ Features

- 🌐 **Works anywhere** — LAN, localhost, or live on the internet
- 🗄️ **No database required** — uses flat JSON files for storage
- 💬 **Real-time messaging** — instant chat updates without page reloads
- 😍 **Reactions** — react to messages with emoji
- 📁 **File uploads** — share files directly in chat
- 🔐 **User authentication** — register, login, and session management
- 🛡️ **Admin panel** — manage users and chat logs with ease
- 🔒 **Secret admin access** — extra layer of admin security

---

## 🗂️ File Structure

```
Chatlify/
├── index.php               # Main chat interface
├── login.php               # Login page
├── register.php            # Registration page
├── send.php                # Handle sending messages
├── load.php                # Load/fetch messages
├── react.php               # Handle emoji reactions
├── delete.php              # Delete messages
├── upload.php              # File upload handler
├── router.php              # Request router
├── check_session.php       # Session validation
├── logout.php              # Logout handler
├── validate_secret.php     # Secret key validation
├── secretadmin1.php        # Admin panel (step 1)
├── secretadmin2.php        # Admin panel (step 2)
├── admin.html              # Admin UI
├── chatlogs.json           # Chat message storage
├── users.json              # User data storage
├── reactions.json          # Reactions storage
├── seen.json               # Read receipts storage
├── session.json            # Active sessions
├── uploads_meta.json       # Upload metadata
├── secret.json             # Secret admin credentials
├── php.ini                 # PHP configuration
├── .htaccess               # Apache rewrite rules
└── uploads/                # Uploaded files directory
```

---

## 🚀 Getting Started

### Requirements

- PHP 7.4 or higher
- Apache or Nginx web server (or PHP built-in server for local use)
- Write permissions on the project directory

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/Finnapple/Chatlify.git
   cd Chatlify
   ```

2. **Set permissions** (Linux/Mac)
   ```bash
   chmod 755 .
   chmod 644 *.json
   ```

3. **Start the server**

   **Option A — PHP built-in server (quickest):**
   ```bash
   php -S localhost:8080 router.php
   ```
   Then open `http://localhost:8080` in your browser.

   **Option B — Apache/Nginx:**
   Drop the folder into your web root (e.g., `/var/www/html/chatlify`) and navigate to it in your browser.

4. **Register an account** and start chatting!

---

## 🏠 Local Network (LAN) Usage

Want to chat with others on the same Wi-Fi?

1. Find your local IP address:
   ```bash
   # Windows
   ipconfig

   # Mac/Linux
   ifconfig or ip a
   ```

2. Start the PHP server bound to your LAN IP:
   ```bash
   php -S 0.0.0.0:8080 router.php
   ```

3. Share `http://YOUR_LAN_IP:8080` with others on the network.

---

## 🛡️ Admin Panel

Chatlify includes a protected admin panel for managing users and chat history.

- Access is protected by a **secret key** stored in `secret.json`
- Two-step admin authentication via `secretadmin1.php` and `secretadmin2.php`
- Admin functions include viewing/deleting chat logs and managing users

> ⚠️ **Important:** Change the default secret key in `secret.json` before deploying publicly!

---

## 🌍 Deploying to the Web

1. Upload all files to your web host via FTP or your hosting panel
2. Ensure PHP is enabled and the directory has write permissions
3. Rename `htaccess.txt` to `.htaccess`
4. For the uploads folder, apply the rules in `httaccess for uploads folder.txt`
5. Visit your domain and register your first account

---

## 🔧 Configuration

You can tweak PHP settings via `php.ini` — useful for adjusting upload size limits:

```ini
upload_max_filesize = 10M
post_max_size = 10M
```

---

## 🤝 Contributing

Contributions, issues, and feature requests are welcome!

1. Fork the repo
2. Create your branch: `git checkout -b feature/your-feature`
3. Commit your changes: `git commit -m 'Add some feature'`
4. Push to the branch: `git push origin feature/your-feature`
5. Open a Pull Request

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

---

## 👤 Author

**Finnapple**
- GitHub: [@Finnapple](https://github.com/Finnapple)

---

<p align="center">Made with ❤️ and PHP</p>

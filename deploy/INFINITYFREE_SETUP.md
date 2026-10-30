# How to Host FinSync on InfinityFree (100% Free)

This guide fixes the **"Database not connected"** error and explains how to get your website running on InfinityFree.

---

## 1. Why "Database Not Connected" Happens on InfinityFree

InfinityFree works differently from local servers (XAMPP/localhost):

1. **MySQL is NEVER `localhost` or `127.0.0.1`**:
   InfinityFree hosts databases on separate dedicated database servers (e.g. `sql107.infinityfree.com` or `sql200.epizy.com`).
2. **Database & Username have prefixes**:
   Your database name is **not** `finsync` — it is `if0_43306084_finsync`.
   Your username is **not** `root` — it is `if0_43306084`.
3. **Database password is your vPanel Password**:
   It is your *Hosting Account Password* (found in your InfinityFree Client Area), **not** your email password.
4. **`config.local.php` was missing**:
   Because `config.local.php` contains private database passwords, it is kept private by Git. When uploading files, you must create or upload `config.local.php` to InfinityFree!

---

## 2. Test Your Connection with the Built-in Diagnostic Page

Open this URL in your phone or computer browser:

```text
http://YOUR-INFINITYFREE-DOMAIN/test_db.php
```
*(or `http://YOUR-INFINITYFREE-DOMAIN/FinSync-main/test_db.php` if you uploaded inside a subfolder)*

This diagnostic page checks your exact database connection in real time and tells you:
- Whether `config.local.php` was detected
- The exact MySQL error if the connection failed
- How many database tables exist

---

## 3. The Correct `config.local.php` for InfinityFree

In your InfinityFree **Online File Manager**, open the folder where you uploaded FinSync (e.g., inside `htdocs/` or `htdocs/FinSync-main/`), create a file named **`config.local.php`**, and paste this:

```php
<?php
declare(strict_types=1);

return [
    'db' => [
        // Find your exact MySQL Host in InfinityFree cPanel -> MySQL Databases
        'host' => 'sql107.infinityfree.com',
        'port' => 3306,
        // Your database name with your account prefix
        'database' => 'if0_43306084_finsync',
        // Your InfinityFree account username
        'username' => 'if0_43306084',
        // Your InfinityFree hosting account password (from Client Area -> Account Details)
        'password' => 'YOUR_INFINITYFREE_HOSTING_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        // Set to '' if uploaded directly to htdocs/, or '/FinSync-main/public' if in a subfolder
        'base_path' => '',
        'environment' => 'production',
    ],
    'google' => [
        // Paste your Google OAuth 2.0 Web Client ID here
        'client_id' => '',
    ],
];
```

---

## 4. Import the Database Tables via phpMyAdmin

If the diagnostic page connects but shows **0 tables found**:

1. In your InfinityFree Control Panel (cPanel), scroll to **Databases** $\rightarrow$ click **phpMyAdmin**.
2. Click **Connect** next to your database (`if0_43306084_finsync`).
3. Click the **Import** tab at the top.
4. Click **Choose File** and select `database/schema.sql` from your computer.
5. Click **Go** (or Import) at the bottom.
6. All tables (`users`, `personal_finance_records`, `investment_holdings`, etc.) will be created immediately!

---

## 5. Enable Real Google Account Chooser on Mobile

To make Google show the **native mobile account picker popup** (with the user's real Google accounts):

1. Go to **[Google Cloud Console](https://console.cloud.google.com/)** (free).
2. Create a project named **FinSync**.
3. Go to **APIs & Services** $\rightarrow$ **OAuth consent screen**:
   - Choose **External** $\rightarrow$ enter App name (`FinSync`) and your email.
4. Go to **Credentials** $\rightarrow$ **Create Credentials** $\rightarrow$ **OAuth client ID**:
   - Application type: **Web application**
   - **Authorized JavaScript origins**:
     - `http://localhost:8000`
     - `https://YOUR-INFINITYFREE-DOMAIN` (e.g. `https://finsync.rf.gd`)
   - Click **Create**.
5. Copy your **Client ID** (looks like `xxxx-xxxx.apps.googleusercontent.com`).
6. Paste it into your `config.local.php` under `'client_id' => '...'`.

Now, whenever anyone opens your website on their mobile phone, Google will automatically display the native **"Continue to FinSync as [User Name]"** Google prompt!

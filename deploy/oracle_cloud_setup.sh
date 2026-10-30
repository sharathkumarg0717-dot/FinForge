#!/usr/bin/env bash
# ==============================================================================
# FinSync - Oracle Cloud Infrastructure (OCI) Production Setup Script
# Works on Ubuntu 22.04 / 24.04 LTS and Debian on Oracle Cloud Compute VMs.
# ==============================================================================

set -euo pipefail

echo "========================================================"
echo "  FinSync Oracle Cloud Production Deployment Script     "
echo "========================================================"

# Check root
if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Please run as root (use: sudo bash deploy/oracle_cloud_setup.sh)"
  exit 1
fi

# Update packages
echo "1. Updating system packages..."
apt-get update -y && apt-get upgrade -y

# Install dependencies, Apache, PHP 8.2+, MySQL
echo "2. Installing Apache, PHP 8.2, MySQL, and dependencies..."
apt-get install -y software-properties-common ca-certificates lsb-release apt-transport-https curl git unzip

add-apt-repository -y ppa:ondrej/php || true
apt-get update -y

apt-get install -y \
  apache2 \
  mysql-server \
  php8.2 \
  php8.2-mysql \
  php8.2-curl \
  php8.2-mbstring \
  php8.2-xml \
  php8.2-zip \
  php8.2-opcache \
  libapache2-mod-php8.2 \
  certbot \
  python3-certbot-apache

# Enable Apache modules
echo "3. Configuring Apache modules..."
a2enmod rewrite headers ssl opcache

# Start and enable services
systemctl enable apache2
systemctl start apache2
systemctl enable mysql
systemctl start mysql

# Configure MySQL database
echo "4. Setting up MySQL database 'finsync'..."
DB_NAME="finsync"
DB_USER="finsync_user"
DB_PASS="FinSync@SecurePass2026!"

mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Deploy application code
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="/var/www/finsync"
echo "5. Deploying FinSync codebase to ${APP_DIR}..."

mkdir -p "${APP_DIR}"

if [ "${SCRIPT_DIR}" != "${APP_DIR}" ] && [ -f "${SCRIPT_DIR}/public/index.php" ]; then
  echo "Deploying files from current workspace (${SCRIPT_DIR}) into ${APP_DIR}..."
  cp -a "${SCRIPT_DIR}/." "${APP_DIR}/"
elif [ -d "${APP_DIR}/.git" ]; then
  echo "Pulling latest changes from Git..."
  cd "${APP_DIR}"
  git pull origin main || true
elif [ -f "${APP_DIR}/public/index.php" ]; then
  echo "Application files verified in ${APP_DIR}."
else
  echo "Cloning repository from GitHub..."
  git clone https://github.com/sharathkumarg0717-dot/FinSync.git "${APP_DIR}" || {
    echo "[!] If your GitHub repo is private, please ensure you are authenticated or copy the folder with scp."
  }
fi
cd "${APP_DIR}"

# Import schema into MySQL
echo "6. Initializing database schema..."
mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${APP_DIR}/database/schema.sql"

# Apply Zerodha Demat migration
echo "7. Running migrations..."
php "${APP_DIR}/database/apply_zerodha_demat_migration.php" || true

# Create production config
echo "8. Creating production configuration..."
cat <<EOF > "${APP_DIR}/config.local.php"
<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => '${DB_NAME}',
        'username' => '${DB_USER}',
        'password' => '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_path' => '',
        'environment' => 'production',
    ],
    'google' => [
        'client_id' => '',
    ],
];
EOF

# Set permissions
echo "9. Setting file permissions..."
chown -R www-data:www-data "${APP_DIR}"
chmod -R 755 "${APP_DIR}"
chmod 640 "${APP_DIR}/config.local.php"

# Configure Apache Virtual Host
echo "10. Configuring Apache VirtualHost..."
cat <<EOF > /etc/apache2/sites-available/finsync.conf
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot ${APP_DIR}/public

    <Directory ${APP_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/finsync_error.log
    CustomLog \${APACHE_LOG_DIR}/finsync_access.log combined
</VirtualHost>
EOF

a2dissite 000-default.conf || true
a2ensite finsync.conf
systemctl reload apache2

# Configure Oracle Cloud iptables / ufw firewall for ports 80 and 443
echo "11. Configuring host firewall for HTTP (80) & HTTPS (443)..."
if command -v ufw >/dev/null 2>&1; then
  ufw allow 80/tcp || true
  ufw allow 443/tcp || true
fi

# Oracle Cloud images typically have default iptables rules blocking ingress:
iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport 80 -j ACCEPT
iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport 443 -j ACCEPT

# Save iptables rules
if command -v netfilter-persistent >/dev/null 2>&1; then
  netfilter-persistent save || true
fi

echo "========================================================"
echo "  FinSync Deployment Completed Successfully!           "
echo "========================================================"
echo "You can now access your website at: http://$(curl -s ifconfig.me || hostname -I | awk '{print $1}')"
echo ""
echo "IMPORTANT ORACLE CLOUD STEP:"
echo "Remember to allow Port 80 and 443 Ingress in your"
echo "Oracle Cloud Console -> Virtual Cloud Network -> Security List!"
echo "========================================================"


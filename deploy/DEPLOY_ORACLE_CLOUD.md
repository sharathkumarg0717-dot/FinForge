# Deploying FinSync on Oracle Cloud (Always Free Tier)

This guide walks you through deploying **FinSync** on **Oracle Cloud Infrastructure (OCI)** completely free forever using the **OCI Always Free Tier**.

---

## Why Oracle Cloud for FinSync?

FinSync is built with **PHP 8.2 + MySQL (PDO)**:
- **Oracle Cloud Always Free Tier** gives you:
  - **Free Compute Instances** (4 OCPU ARM Ampere A1 with 24 GB RAM, or 2 AMD Micro VMs).
  - Full root access to run PHP 8.2, Apache/Nginx, and native MySQL.
  - Free Static Public IP address.
  - Zero recurring cost.
- **Firebase** only natively hosts static files or Node.js serverless functions (it does not run PHP or MySQL databases directly).

---

## Step 1: Create an Always Free Instance on Oracle Cloud

1. Log in to [Oracle Cloud Console](https://cloud.oracle.com).
2. On the Dashboard, click **Create a VM instance**.
3. Configure the VM:
   - **Name**: `finsync-production`
   - **Image**: **Ubuntu 22.04 LTS** or **Ubuntu 24.04 LTS** (or Oracle Linux).
   - **Shape**:
     - *Recommended*: **Ampere (VM.Standard.A1.Flex)** with 2 to 4 OCPUs and 12 to 24 GB RAM (Always Free Eligible).
     - *Alternative*: **VM.Standard.E2.1.Micro** (AMD Always Free).
   - **Networking**: Select your default VCN and subnet (ensure **Assign a public IPv4 address** is checked).
   - **SSH Keys**: Download your private key (`.key` file) or paste your existing public SSH key.
4. Click **Create**. Within 1–2 minutes, your instance will be running with a **Public IP Address** (e.g. `129.146.xxx.xxx`).

---

## Step 2: Open Ingress Ports (80 & 443) in Oracle Cloud

Oracle Cloud blocks incoming web traffic by default. You must open HTTP and HTTPS:

1. In the OCI Console, go to your instance details and click on the **Subnet** name under **Primary VNIC**.
2. Click on the **Default Security List**.
3. Click **Add Ingress Rules**:
   - **Source CIDR**: `0.0.0.0/0`
   - **IP Protocol**: `TCP`
   - **Destination Port Range**: `80,443`
   - **Description**: `Allow HTTP and HTTPS traffic`
4. Click **Add Ingress Rules**.

---

## Step 3: Deploy FinSync (One-Command Automated Deployment)

1. Open your terminal (PowerShell, Command Prompt, or Git Bash) on your computer.
2. SSH into your Oracle Cloud instance:
   ```bash
   ssh -i /path/to/your-private-key.key ubuntu@<YOUR_ORACLE_PUBLIC_IP>
   ```

3. Run the automated FinSync deployment script:
   ```bash
   curl -fsSL https://raw.githubusercontent.com/sharathkumarg0717-dot/FinSync/main/deploy/oracle_cloud_setup.sh | sudo bash
   ```

This single command will automatically:
- Install Apache, PHP 8.2 with PDO MySQL, and MySQL 8.
- Clone the FinSync codebase from your GitHub repository.
- Create and configure the MySQL database with your tables and schema.
- Apply database migrations.
- Configure web server permissions and iptables rules.

---

## Method 4 (Alternative): Deploy using Docker & Docker Compose

If you prefer Docker on Oracle Cloud:

1. SSH into your Oracle Cloud VM:
   ```bash
   ssh -i /path/to/your-private-key.key ubuntu@<YOUR_ORACLE_PUBLIC_IP>
   ```
2. Install Docker and Docker Compose:
   ```bash
   sudo apt update && sudo apt install -y docker.io docker-compose
   sudo usermod -aG docker ubuntu
   ```
3. Clone your FinSync repository:
   ```bash
   git clone https://github.com/sharathkumarg0717-dot/FinForge.git
   cd FinSync
   ```
4. Start FinSync with Docker Compose:
   ```bash
   sudo docker-compose up -d
   ```

---

## Step 5: Access Your Live FinSync Website

Open your browser and navigate to:
```
http://<YOUR_ORACLE_PUBLIC_IP>
```

### Free HTTPS / SSL (Optional):
If you have a domain name pointing to your Oracle IP, run:
```bash
sudo certbot --apache -d yourdomain.com
```
Certbot will issue and automatically renew a free Let's Encrypt SSL certificate!


# Why Oracle Cloud vs Firebase for FinSync?

### 1. Technology Comparison

| Feature | Oracle Cloud (OCI Always Free) | Firebase Hosting |
| :--- | :--- | :--- |
| **Backend Runtime** | **Full Native PHP 8.2+ Support** | Node.js / Static Only |
| **Database** | **Native MySQL 8.0 Included** | No SQL database (Firestore NoSQL only) |
| **Cost** | **100% Free Forever** (Ampere 24GB RAM / 4 OCPU) | Free for static, Paid Cloud SQL for MySQL |
| **Ease for PHP** | **Instant 1-command deployment** | Requires rewriting to Node.js or Docker on Cloud Run |

---

### Can you use Firebase with FinSync?

- **Firebase Hosting** was designed for Single Page Applications (React, Vue, static HTML/JS) and serverless Node.js Cloud Functions. It **cannot run PHP scripts directly** and does not provide a free MySQL database.
- If you want to use Google Cloud / Firebase infrastructure for FinSync, you can deploy FinSync's container (`Dockerfile`) to **Google Cloud Run** and connect it to **Google Cloud SQL (MySQL)**. However, Cloud SQL incurs recurring monthly costs (~$10–$30/month) outside Google's free tier.
- **Oracle Cloud Infrastructure (OCI)** provides a **permanent 100% Always Free Tier** VM with native PHP 8.2 and native MySQL with zero cost, making it the ideal choice for FinSync.


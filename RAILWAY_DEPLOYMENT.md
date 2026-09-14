# SmartBiteCare Railway Deployment

This project is prepared for Railway using a Dockerfile.

## App service variables

After adding a Railway MySQL service named `MySQL`, create these variables in the SmartBiteCare app service:

```text
SMARTBITECARE_DB_HOST=${{MySQL.MYSQLHOST}}
SMARTBITECARE_DB_PORT=${{MySQL.MYSQLPORT}}
SMARTBITECARE_DB_USER=${{MySQL.MYSQLUSER}}
SMARTBITECARE_DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
SMARTBITECARE_DB_NAME=${{MySQL.MYSQLDATABASE}}
```

`SMARTBITECARE_APP_URL` is optional for a Railway-provided domain because `sources/app_config.php` can use `RAILWAY_PUBLIC_DOMAIN` automatically. Set it when you add a custom domain.

## Email variables

Email credentials are no longer stored in PHP source code. Configure them in Railway:

```text
SMARTBITECARE_SMTP_HOST=smtp.gmail.com
SMARTBITECARE_SMTP_PORT=587
SMARTBITECARE_SMTP_USERNAME=your-email@example.com
SMARTBITECARE_SMTP_PASSWORD=your-app-password
SMARTBITECARE_SMTP_FROM=your-email@example.com
SMARTBITECARE_SMTP_FROM_NAME=SmartBiteCare
```

## Persistent uploads

Attach a Railway Volume to the application service with mount path:

```text
/var/www/html/uploads
```

Without a volume, generated/uploaded documents can disappear after redeployments.

## Database import

Import `database/smartbitecare.sql` into the Railway MySQL database once after provisioning it.

From Windows/XAMPP, use the public MySQL connection information from Railway:

```bat
C:\xampp\mysql\bin\mysql.exe -h HOST -P PORT -u USER -p DATABASE < database\smartbitecare.sql
```

Do not put the MySQL password directly in the command. Enter it when prompted.

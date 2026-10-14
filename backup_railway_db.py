#!/usr/bin/env python3
"""Back up a MySQL database (e.g. Railway MySQL 9) to a UTF-8 .sql file.

Works where XAMPP's old mysqldump cannot log in (caching_sha2_password).
Needs:  py -m pip install mysql-connector-python

Usage (PowerShell):
    py backup_railway_db.py --host hayabusa.proxy.rlwy.net --port 39152 --user root --database railway --out backup_before_new_dataset.sql
The password is asked for at the prompt (not stored in history).

Restore later with any MySQL client, e.g. inside Railway:
    mysql -u root -p railway < backup_before_new_dataset.sql
"""
import argparse
import datetime as dt
import decimal
import getpass

import mysql.connector


def sql_value(v):
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "1" if v else "0"
    if isinstance(v, (int, float, decimal.Decimal)):
        return str(v)
    if isinstance(v, (bytes, bytearray)):
        return "X'" + bytes(v).hex() + "'" if v else "''"
    if isinstance(v, (dt.datetime, dt.date, dt.time, dt.timedelta)):
        return "'" + str(v) + "'"
    s = str(v)
    s = (s.replace("\\", "\\\\").replace("'", "\\'").replace("\n", "\\n")
          .replace("\r", "\\r").replace("\x00", "\\0").replace("\x1a", "\\Z"))
    return "'" + s + "'"


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--host", required=True)
    p.add_argument("--port", type=int, default=3306)
    p.add_argument("--user", default="root")
    p.add_argument("--database", default="railway")
    p.add_argument("--out", default="backup.sql")
    a = p.parse_args()
    pw = getpass.getpass("Enter password: ")

    conn = mysql.connector.connect(host=a.host, port=a.port, user=a.user, password=pw,
                                   database=a.database, charset="utf8mb4", use_pure=True)
    cur = conn.cursor()
    cur.execute("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ")
    cur.execute("START TRANSACTION WITH CONSISTENT SNAPSHOT")
    cur.execute("SET time_zone = '+00:00'")
    cur.execute("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
    tables = [r[0] for r in cur.fetchall()]

    total_rows = 0
    with open(a.out, "w", encoding="utf-8", newline="\n") as f:
        f.write(f"-- Backup of `{a.database}` from {a.host}:{a.port} at {dt.datetime.now():%Y-%m-%d %H:%M:%S}\n")
        f.write("SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\n"
                "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n")
        for t in tables:
            cur.execute(f"SHOW CREATE TABLE `{t}`")
            create = cur.fetchone()[1]
            f.write(f"--\n-- Table `{t}`\n--\nDROP TABLE IF EXISTS `{t}`;\n{create};\n\n")
            cur.execute(f"SELECT * FROM `{t}`")
            cols = ", ".join(f"`{d[0]}`" for d in cur.description)
            batch = []
            n = 0
            for row in cur:
                batch.append("(" + ",".join(sql_value(v) for v in row) + ")")
                n += 1
                if len(batch) == 200:
                    f.write(f"INSERT INTO `{t}` ({cols}) VALUES\n" + ",\n".join(batch) + ";\n")
                    batch = []
            if batch:
                f.write(f"INSERT INTO `{t}` ({cols}) VALUES\n" + ",\n".join(batch) + ";\n")
            f.write("\n")
            total_rows += n
            print(f"  {t}: {n} rows")
        f.write("SET FOREIGN_KEY_CHECKS = 1;\n")
    conn.rollback()
    conn.close()
    print(f"Done: {len(tables)} tables, {total_rows} rows -> {a.out}")


if __name__ == "__main__":
    main()
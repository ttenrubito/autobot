#!/usr/bin/env python3
"""Apply required DB schema changes for GCS document uploads in PRODUCTION.

Why this exists:
- `gcloud sql connect` fails from some IPv6 networks.
- This script connects directly to Cloud SQL over the instance public IPv4.

It is intentionally idempotent (safe to re-run).

Note: Cloud SQL for MySQL is often 5.7, which does NOT support:
- ALTER TABLE ... ADD COLUMN IF NOT EXISTS
- CREATE INDEX IF NOT EXISTS
So we do explicit existence checks via information_schema.
"""

import os
import pymysql

HOST = os.environ.get("DB_HOST", "34.142.150.88")
PORT = int(os.environ.get("DB_PORT", "3306"))
DB = os.environ.get("DB_NAME", "autobot")
USER = os.environ.get("DB_USER", "autobot_app")
PASSWORD = os.environ.get("DB_PASSWORD", "Password@9")

VERIFY_SQL = "SHOW COLUMNS FROM application_documents;"


def column_exists(cur, table: str, column: str) -> bool:
    cur.execute(
        """
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s
        LIMIT 1
        """,
        (DB, table, column),
    )
    return cur.fetchone() is not None


def index_exists(cur, table: str, index: str) -> bool:
    cur.execute(
        """
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s
        LIMIT 1
        """,
        (DB, table, index),
    )
    return cur.fetchone() is not None


def main() -> int:
    print("== Applying production migration: application_documents GCS columns ==")
    print(f"Connecting to {HOST}:{PORT} db={DB} user={USER}")

    conn = pymysql.connect(
        host=HOST,
        port=PORT,
        user=USER,
        password=PASSWORD,
        database=DB,
        charset="utf8mb4",
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=10,
        read_timeout=30,
        write_timeout=30,
    )

    try:
        with conn.cursor() as cur:
            # Columns
            cols_to_add = [
                (
                    "file_path",
                    "ALTER TABLE application_documents ADD COLUMN file_path VARCHAR(500) NULL COMMENT 'Legacy: Local file path (deprecated - use gcs_path instead)'",
                ),
                (
                    "document_label",
                    "ALTER TABLE application_documents ADD COLUMN document_label VARCHAR(255) NULL COMMENT 'Human readable label (e.g., บัตรประชาชน)'",
                ),
                (
                    "gcs_path",
                    "ALTER TABLE application_documents ADD COLUMN gcs_path VARCHAR(500) NULL COMMENT 'Path in Google Cloud Storage bucket'",
                ),
                (
                    "gcs_signed_url",
                    "ALTER TABLE application_documents ADD COLUMN gcs_signed_url TEXT NULL COMMENT 'GCS signed URL (temporary, expires)'",
                ),
                (
                    "gcs_signed_url_expires_at",
                    "ALTER TABLE application_documents ADD COLUMN gcs_signed_url_expires_at DATETIME NULL COMMENT 'Expiration time for signed URL'",
                ),
            ]

            for name, ddl in cols_to_add:
                if column_exists(cur, "application_documents", name):
                    print(f"-- Skip: column {name} already exists")
                    continue
                print(f"-- Add column: {name}")
                cur.execute(ddl)

            # Index
            if index_exists(cur, "application_documents", "idx_gcs_path"):
                print("-- Skip: index idx_gcs_path already exists")
            else:
                print("-- Create index: idx_gcs_path")
                cur.execute("CREATE INDEX idx_gcs_path ON application_documents(gcs_path)")

            print("-- Verify columns")
            cur.execute(VERIFY_SQL)
            cols = cur.fetchall()
            wanted = {"file_path", "document_label", "gcs_path", "gcs_signed_url", "gcs_signed_url_expires_at"}
            for c in cols:
                if c.get("Field") in wanted:
                    print(f"  ✅ {c['Field']} {c['Type']} NULL={c['Null']} DEFAULT={c['Default']}")

        print("✅ Migration complete")
        return 0
    finally:
        conn.close()


if __name__ == "__main__":
    raise SystemExit(main())

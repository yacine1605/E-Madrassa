# The platform's backups: PostgreSQL 16's own tools, GnuPG for the encryption, rclone for the
# files' bucket (any S3-compatible storage).
FROM postgres:16-alpine
RUN apk add --no-cache python3 gnupg rclone
COPY docker/backup/backup.py /opt/madrassa/backup.py
ENTRYPOINT ["python3", "/opt/madrassa/backup.py"]
CMD ["schedule"]

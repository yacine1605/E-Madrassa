FROM postgres:16-alpine
RUN apk add --no-cache python3 gnupg
COPY docker/backup/backup.py /opt/madrassa/backup.py
ENTRYPOINT ["python3", "/opt/madrassa/backup.py"]
CMD ["schedule"]

FROM postgres:16.10

# Install sed and cron
RUN apt-get update && apt-get install -y sed cron && rm -rf /var/lib/apt/lists/*

# Add init SQL and replace placeholder
COPY init.sql /init.sql
ARG ADMIN_EPPN
RUN sed "s/<ADMIN_EPPN>/$ADMIN_EPPN/g" "/init.sql" > "/docker-entrypoint-initdb.d/init.sql"

# Copy the update script and make it executable
COPY decrement_archive.sh /usr/local/bin/decrement_archive.sh
RUN chmod +x /usr/local/bin/decrement_archive.sh

# Add the cron job
RUN echo "0 0 * * * /usr/local/bin/decrement_archive.sh" > /etc/cron.d/daily-update && \
    chmod 0644 /etc/cron.d/daily-update

# Run cron and postgres when the container starts
CMD cron && docker-entrypoint.sh postgres

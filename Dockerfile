# Margaux Collections — Railway deployment image
# Serves the app at /Margaux_Collections/ (same as your XAMPP setup)
# so none of your existing hardcoded "/Margaux_Collections/..." paths
# need to change.
FROM php:8.2-apache

# mysqli is required by config/database.php
RUN docker-php-ext-install mysqli
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
RUN a2enmod mpm_prefork rewrite
RUN apache2ctl -M

# Copy the whole project into /var/www/html/Margaux_Collections
# (mirrors exactly where it lives inside XAMPP's htdocs)
COPY . /var/www/html/Margaux_Collections/

# Serve the app's landing page directly at the bare domain ("/") without
# changing the URL — the app's code still lives under /Margaux_Collections/
# and keeps using its existing "/Margaux_Collections/..." absolute paths.
RUN printf '<?php\nchdir("/var/www/html/Margaux_Collections");\nrequire "/var/www/html/Margaux_Collections/index.php";\n' \
    > /var/www/html/index.php

# Site-wide favicon: browsers automatically request /favicon.ico at the domain
# root for ANY page that doesn't declare its own <link rel="icon">, regardless
# of how deep the page's own path is. Placing the logo here makes the tab icon
# show up on every page (login, register, products, etc.) without editing each file.
RUN cp /var/www/html/Margaux_Collections/images/logo.jpg /var/www/html/favicon.ico

# Give Apache write access to the folders the app writes to (profile photo
# uploads, homepage slot images)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/Margaux_Collections/uploads \
    && chmod -R 755 /var/www/html/Margaux_Collections/images

# Railway assigns a random $PORT at runtime — Apache needs to listen on it
COPY start-apache.sh /usr/local/bin/start-apache.sh 
RUN chmod +x /usr/local/bin/start-apache.sh

EXPOSE 80
CMD ["/usr/local/bin/start-apache.sh"]

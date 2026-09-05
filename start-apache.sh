#!/bin/bash
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf
a2enmod mpm_prefork >/dev/null 2>&1 || true
sed -i "s/80/${PORT:-80}/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf
ls -la /etc/apache2/mods-enabled/ | grep mpm

# Fix ownership on the mounted volume — this must run at runtime because
# the Railway Volume overrides whatever permissions were set at build time
# in the Dockerfile (chown there only applies to the image, not the volume
# that gets mounted on top of it at container start).
mkdir -p /var/www/html/Margaux_Collections/images/products
mkdir -p /var/www/html/Margaux_Collections/images/homepage
chown -R www-data:www-data /var/www/html/Margaux_Collections/images
chmod -R 755 /var/www/html/Margaux_Collections/images

   mkdir -p /var/www/html/Margaux_Collections/images/products
   mkdir -p /var/www/html/Margaux_Collections/images/homepage
   chown -R www-data:www-data /var/www/html/Margaux_Collections/images
   chmod -R 755 /var/www/html/Margaux_Collections/images
   if [ ! -f /var/www/html/Margaux_Collections/images/logo.jpg ]; then
     cp /var/www/html/Margaux_Collections/assets-backup/logo.jpg /var/www/html/Margaux_Collections/images/logo.jpg
     chown www-data:www-data /var/www/html/Margaux_Collections/images/logo.jpg
   fi

exec apache2-foreground

#!/bin/bash

sudo apt-get install wget sed php php-cli php-imap php-curl php-xml

if [ $? != "0" ]
then
	echo "MailPet won't function well unless all fundamental PHP packages are installed, aborting installation..."
	exit 1
fi

# COMPOSER INSTALLATION

# This part was added as perscribed at: https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md
# The only modifications made were to:
#	- check for error codes during composer-installation
# 	- modify some error messages and exit code(s) to be become more familiar with the context

EXPECTED_CHECKSUM="$(wget -q -O - https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]
then
    >&2 echo 'COMPOSER ERROR: Invalid installer checksum'
    rm composer-setup.php
    exit 2
fi

php composer-setup.php --quiet

if [ $? != "0" ]
then
	echo "Composer installation failed. MailPet requires Composer to be installed, aborting installation ..."
	exit 3
fi

rm composer-setup.php

# END OF COMPOSER INSTALLATION

./composer.phar install

if [ $? != "0" ]
then
	echo "MailPet requires certain Composer packages to be downloaded (PHPMailer, Parallel), aborting installation..."
	exit 4
fi

cur_path=${PWD//\//\\/}
cur_path="s/PWD/$cur_path/g"

sed $cur_path -i mailpet

chmod +x mailpet
sudo ln -f mailpet /usr/sbin/mailpet

echo "Done installing MailPet"

mailpet
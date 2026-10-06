#!/bin/bash
php generate-key.php
heroku-php-apache2 -C ./apache.conf -F ./php-fpm.conf public_html/

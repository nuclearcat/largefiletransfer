#!/bin/bash
# run docker with php/web and run in it largefiletransfer.php
sudo docker run -it --rm \
    -v $(pwd):/var/www/html \
    -p 8080:80 \
    php:8.1-apache
FROM php:8.2-apache-bookworm

ENV TZ=Asia/Manila \
    PORT=8080 \
    SMARTBITECARE_PYTHON=/opt/smartbitecare-venv/bin/python


# ============================================================
# SYSTEM + PHP DEPENDENCIES
# ============================================================

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-pip \
        python3-venv \
        default-mysql-client \
        libgomp1 \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libzip-dev \
        libicu-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        pdo_mysql \
        mbstring \
        gd \
        zip \
        intl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*


# ============================================================
# PYTHON / FORECASTING
# ============================================================

COPY requirements-forecasting.txt /tmp/requirements-forecasting.txt

RUN python3 -m venv /opt/smartbitecare-venv \
    && /opt/smartbitecare-venv/bin/pip install \
        --no-cache-dir \
        --upgrade pip \
    && /opt/smartbitecare-venv/bin/pip install \
        --no-cache-dir \
        -r /tmp/requirements-forecasting.txt


# ============================================================
# SMARTBITECARE APPLICATION
# ============================================================

WORKDIR /var/www/html

COPY . /var/www/html/


# ============================================================
# STARTUP SCRIPT
# ============================================================

COPY docker-entrypoint-smartbitecare.sh \
     /usr/local/bin/docker-entrypoint-smartbitecare

RUN chmod +x \
        /usr/local/bin/docker-entrypoint-smartbitecare \
    && mkdir -p /var/www/html/uploads/documents \
    && chown -R www-data:www-data /var/www/html/uploads


# ============================================================
# RAILWAY
# ============================================================

EXPOSE 8080

CMD ["docker-entrypoint-smartbitecare"]
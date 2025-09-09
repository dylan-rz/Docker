FROM ubuntu:24.04

# Install dependencies including FFmpeg dev libraries
RUN apt-get update && apt-get install -y \
    build-essential \
    libpcre3-dev \
    libssl-dev \
    zlib1g-dev \
    libavcodec-dev \
    libavformat-dev \
    libavutil-dev \
    libswscale-dev \
    wget \
    git \
    gettext-base \
    && rm -rf /var/lib/apt/lists/*

# Download Nginx source
RUN wget http://nginx.org/download/nginx-1.26.1.tar.gz && \
    tar -zxvf nginx-1.26.1.tar.gz && \
    rm nginx-1.26.1.tar.gz

# Download Kaltura VOD module
RUN git clone https://github.com/kaltura/nginx-vod-module.git

# Configure and build Nginx with the module (enable file aio and threads)
RUN cd nginx-1.26.1 && \
    ./configure \
        --add-module=../nginx-vod-module \
        --with-http_ssl_module \
        --with-http_v2_module \
        --with-http_sub_module \
        --with-file-aio \
        --with-threads \
        --with-cc-opt='-I/usr/include -O3 -mpopcnt' \
        --with-ld-opt='-L/usr/lib -lavcodec -lavformat -lavutil -lswscale' \
        --prefix=/usr/local/nginx \
        --conf-path=/usr/local/nginx/conf/nginx.conf \
        --error-log-path=/usr/local/nginx/logs/error.log \
        --http-log-path=/usr/local/nginx/logs/access.log \
        --pid-path=/usr/local/nginx/logs/nginx.pid \
        --lock-path=/usr/local/nginx/logs/nginx.lock && \
    make && \
    make install

# Create necessary directories
RUN mkdir -p /usr/local/nginx/cache /usr/local/nginx/logs /usr/local/nginx/html

# Copy templates only; runtime nginx.conf is rendered by entrypoint
COPY certbot/nginx.conf.template /usr/local/nginx/conf/nginx.conf.template
COPY certbot/nginx.nossl.template /usr/local/nginx/conf/nginx.nossl.template
COPY certbot/ssl-params.conf /usr/local/nginx/conf/ssl-params.conf
COPY certbot/renewal/watch-reload.sh /usr/local/bin/watch-reload.sh
RUN chmod +x /usr/local/bin/watch-reload.sh

# Entrypoint for envsubst templating and optional certbot provisioning
COPY certbot/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose ports
EXPOSE 80
EXPOSE 443

# Use entrypoint to render templates and optionally run certbot, then start nginx
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["/usr/local/nginx/sbin/nginx", "-g", "daemon off;"]

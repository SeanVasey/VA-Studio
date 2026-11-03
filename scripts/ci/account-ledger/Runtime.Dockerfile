# This recipe is executed only by the independent ROOT CI producer, never tests.
# Signed base-image sources/keyring remain authoritative; no unsigned repository,
# expired Release, TLS override, external installer or floating language package.
FROM debian@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4

RUN set -eu; \
    install -d -m 0755 /usr/share/va-ledger /usr/share/va-ledger/debs/partial; \
    rm -f /etc/apt/sources.list /etc/apt/sources.list.d/*; \
    printf 'Types: deb\nURIs: http://deb.debian.org/debian\nSuites: trixie trixie-updates\nComponents: main\nSigned-By: /usr/share/keyrings/debian-archive-keyring.pgp\n\nTypes: deb\nURIs: http://deb.debian.org/debian-security\nSuites: trixie-security\nComponents: main\nSigned-By: /usr/share/keyrings/debian-archive-keyring.pgp\n' > /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get -o Dir::Cache::archives=/usr/share/va-ledger/debs \
      -o APT::Keep-Downloaded-Packages=true install --yes --no-install-recommends \
      php8.4-cli=8.4.27-1~deb13u1 \
      php8.4-common=8.4.27-1~deb13u1 \
      php8.4-mysql=8.4.27-1~deb13u1 \
      php8.4-mbstring=8.4.27-1~deb13u1 \
      php8.4-xml=8.4.27-1~deb13u1 \
      php8.4-intl=8.4.27-1~deb13u1 \
      php8.4-bcmath=8.4.27-1~deb13u1 \
      php8.4-gd=8.4.27-1~deb13u1 \
      php8.4-zip=8.4.27-1~deb13u1 \
      php8.4-curl=8.4.27-1~deb13u1 \
      php8.4-sqlite3=8.4.27-1~deb13u1 \
      python3.13-minimal=3.13.5-2+deb13u5 \
      libpython3.13-minimal=3.13.5-2+deb13u5; \
    dpkg-query -W -f='${binary:Package}\t${Version}\t${Architecture}\n' > /usr/share/va-ledger/package-closure.txt; \
    sha256sum /usr/share/va-ledger/debs/*.deb > /usr/share/va-ledger/download-closure.sha256; \
    cp -a /etc/apt/sources.list.d /usr/share/va-ledger/apt-sources; \
    cp -a /var/lib/apt/lists /usr/share/va-ledger/apt-lists; \
    rm -rf /usr/share/va-ledger/debs; \
    rm -rf /var/lib/apt/lists/*

USER 1000:1000
ENTRYPOINT ["/bin/sleep"]
CMD ["120"]

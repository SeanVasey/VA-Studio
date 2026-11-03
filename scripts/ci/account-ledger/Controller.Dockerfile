ARG RUNTIME_IMAGE=debian@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4
FROM --platform=linux/amd64 docker@sha256:b0ea6e8d57b28d04a6999591fdd0f10de8717e956f4f4b6128d2a8cf57b27eb9 AS engine
FROM ${RUNTIME_IMAGE}
USER 0:0
# Static engine provenance is the independently observed official immutable OCI
# manifest above. No host daemon/binary/profile is promoted into inner admission.
COPY --from=engine /usr/local/bin/docker /usr/bin/docker
COPY --from=engine /usr/local/bin/dockerd /usr/bin/dockerd
COPY --from=engine /usr/local/bin/containerd /usr/bin/containerd
COPY --from=engine /usr/local/bin/containerd-shim-runc-v2 /usr/bin/containerd-shim-runc-v2
COPY --from=engine /usr/local/bin/ctr /usr/bin/ctr
COPY --from=engine /usr/local/bin/runc /usr/bin/runc
COPY --from=engine /usr/local/bin/docker-init /usr/bin/docker-init
COPY --from=engine /usr/local/bin/docker-proxy /usr/bin/docker-proxy
COPY --from=engine /usr/local/bin/dockerd-entrypoint.sh /usr/local/bin/dockerd-entrypoint.sh
COPY --from=engine /usr/local/libexec/docker/cli-plugins/docker-buildx /usr/local/libexec/docker/cli-plugins/docker-buildx
RUN --mount=type=secret,id=network_ca,required=true set -eu; apt-get -o Acquire::https::CaInfo=/run/secrets/network_ca update; \
    DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::https::CaInfo=/run/secrets/network_ca install --yes --no-install-recommends \
      iptables iproute2 kmod mount util-linux procps; \
    dpkg-query -W -f='${binary:Package}\t${Version}\t${Architecture}\n' > /usr/share/va-ledger/controller-package-closure.txt; \
    rm -rf /var/lib/apt/lists/*
ENTRYPOINT ["/usr/bin/python3.13", "-E", "-s", "/candidate/scripts/ci/account-ledger/controller.py"]

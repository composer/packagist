FROM openjdk:7-jre-slim

MAINTAINER Alessandro Lai <alessandro.lai85@gmail.com>

RUN apt-get update \
   && apt-get install -y curl \
   && apt-get clean

COPY entrypoint.sh /usr/local/bin/solr-entrypoint.sh
COPY *.xml /etc/solr/

ENTRYPOINT /usr/local/bin/solr-entrypoint.sh

EXPOSE 8983

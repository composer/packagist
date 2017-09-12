FROM openjdk:7-jre-slim

MAINTAINER Alessandro Lai <alessandro.lai85@gmail.com>

RUN apt-get update \
   && apt-get install -y curl \
   && apt-get clean

ARG SOLR_FOLDER='apache-solr-3.6.0'
ENV SOLR_CORE='core0'
ENV SOLR_PORT='8983'

ADD  http://archive.apache.org/dist/lucene/solr/3.6.0/apache-solr-3.6.0.tgz ./
COPY entrypoint.sh /usr/local/bin/solr-entrypoint.sh

RUN tar -zxf apache-solr-3.6.0.tgz \
    && mkdir -p $SOLR_FOLDER/example/multicore/$SOLR_CORE/conf \
    # copy text configs from default single core conf to new core to have proper defaults
    && cp -R $SOLR_FOLDER/example/solr/conf/lang $SOLR_FOLDER/example/multicore/$SOLR_CORE/conf/ \
    && cp $SOLR_FOLDER/example/solr/conf/*.txt $SOLR_FOLDER/example/multicore/$SOLR_CORE/conf/

COPY *.xml $SOLR_FOLDER/example/multicore/$SOLR_CORE/conf/

ENTRYPOINT /usr/local/bin/solr-entrypoint.sh

EXPOSE $SOLR_PORT

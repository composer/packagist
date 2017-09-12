#!/usr/bin/env bash

SOLR_PORT=${SOLR_PORT:-8983}
SOLR_VERSION=${SOLR_VERSION:-4.9.1}
DEBUG=${DEBUG:-false}
SOLR_CORE=${SOLR_CORE:-core0}
# Since Solr 5.x
SOLR_COLLECTION=${SOLR_COLLECTION:-gettingstarted}

download() {
    FILE="$2.tgz"
    if [ -f $FILE ];
    then
       echo "File $FILE exists."
       tar -zxf $FILE
    else
       echo "File $FILE does not exist. Downloading solr from $1..."
       curl -O $1
       tar -zxf $FILE
    fi
    echo "Downloaded!"
}

is_solr_up(){
    echo "Checking if solr is up on http://localhost:$SOLR_PORT/solr/admin/cores"
    http_code=`echo $(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$SOLR_PORT/solr/admin/cores")`
    return `test $http_code = "200"`
}

wait_for_solr(){
    while ! is_solr_up; do
        sleep 3
    done
}

keep_solr_up(){
    trap "exit" INT
    while is_solr_up; do
        echo "Still up!" 
        sleep 2
    done
}

run() {
    dir_name=$1
    solr_port=$2
    solr_core=$3
    # Run solr
    echo "Running with folder $dir_name"
    echo "Starting solr on port ${solr_port}..."

    # go to the solr folder
    cd $1/example

    if [ "$DEBUG" = "true" ]
    then
        java -Djetty.port=$solr_port -Dsolr.solr.home=multicore -jar start.jar &
    else
        java -Djetty.port=$solr_port -Dsolr.solr.home=multicore -jar start.jar > /dev/null 2>&1 &
    fi
    wait_for_solr
    cd ../../
    echo "Started"
}

run_solr5_example() {
    dir_name=$1
    solr_port=$2
    ./$dir_name/bin/solr -p $solr_port -c -e schemaless
    echo "Started"
}

run_solr5() {
    dir_name=$1
    solr_port=$2
    ./$dir_name/bin/solr -p $solr_port -c
    echo "Started"
}

download_and_run() {
	version=$1
    case $1 in
        3.*)
            url="http://archive.apache.org/dist/lucene/solr/${version}/apache-solr-${version}.tgz"
            dir_name="apache-solr-${version}"
            dir_conf="conf/"
            ;;
        4.0.0)
            url="http://archive.apache.org/dist/lucene/solr/4.0.0/apache-solr-4.0.0.tgz"
            dir_name="apache-solr-4.0.0"
            dir_conf="collection1/conf/"
            ;;
        4.*)
            url="http://archive.apache.org/dist/lucene/solr/${version}/solr-${version}.tgz"
            dir_name="solr-${version}"
            dir_conf="collection1/conf/"
            ;;
        5.*|6.*)
            url="http://archive.apache.org/dist/lucene/solr/${version}/solr-${version}.tgz"
            dir_name="solr-${version}"
            ;;
        *)
			echo "Sorry, $1 is not supported or not valid version."
			exit 1
    esac

    download $url $dir_name
    if [[ $1 == 5* || $1 == 6* ]]
    then
        if [ -z "${SOLR_COLLECTION_CONF}" ]
        then
            run_solr5_example $dir_name $SOLR_PORT
        else
            run_solr5 $dir_name $SOLR_PORT
            create_collection $dir_name $SOLR_COLLECTION $SOLR_COLLECTION_CONF $SOLR_PORT
        fi
        if [ -z "${SOLR_DOCS}" ]
        then
            echo "SOLR_DOCS not defined, skipping initial indexing"
        else
            post_documents_solr5 $dir_name $SOLR_COLLECTION $SOLR_DOCS $SOLR_PORT
        fi
    else
        add_core $dir_name $dir_conf $SOLR_CORE "$SOLR_CONFS"
        run $dir_name $SOLR_PORT $SOLR_CORE
         if [ -z "${SOLR_DOCS}" ]
        then
            echo "SOLR_DOCS not defined, skipping initial indexing"
        else
            post_documents $dir_name $SOLR_DOCS $SOLR_CORE $SOLR_PORT
        fi
    fi
}

add_core() {
    dir_name=$1
    dir_conf=$2
    solr_core=$3
    solr_confs=$4
    # prepare our folders
    [[ -d "${dir_name}/example/multicore/${solr_core}" ]] || mkdir $dir_name/example/multicore/$solr_core
    [[ -d "${dir_name}/example/multicore/${solr_core}/conf" ]] || mkdir $dir_name/example/multicore/$solr_core/conf

    # copy text configs from default single core conf to new core to have proper defaults
    cp -R $dir_name/example/solr/conf/{lang,*.txt} $dir_name/example/multicore/$solr_core/conf/

    # copies custom configurations
    if [ -d "${solr_confs}" ] ; then
      cp -R $solr_confs/* $dir_name/example/multicore/$solr_core/conf/
      echo "Copied $solr_confs/* to solr conf directory."
    else
      for file in $solr_confs
      do
        if [ -f "${file}" ]; then
            cp $file $dir_name/example/multicore/$solr_core/conf
            echo "Copied $file into solr conf directory."
        else
            echo "${file} is not valid";
            exit 1
        fi
      done
    fi

    # enable custom core
    if [ "$solr_core" != "core0" -a "$solr_core" != "core1" ] ; then
        echo "Adding $solr_core to solr.xml"
        sed -i -e "s/<\/cores>/<core name=\"$solr_core\" instanceDir=\"$solr_core\" \/><\/cores>/" $dir_name/example/multicore/solr.xml
    fi
}

post_documents() {
    dir_name=$1
    solr_docs=$2
    solr_core=$3
    solr_port=$4
      # Post documents
    if [ -z "${solr_docs}" ]
    then
        echo "SOLR_DOCS not defined, skipping initial indexing"
    else
        echo "Indexing $solr_docs"
        java -Dtype=application/json -Durl=http://localhost:$solr_port/solr/$solr_core/update/json -jar $dir_name/example/exampledocs/post.jar $solr_docs
    fi
}

create_collection() {
    dir_name=$1
    name=$2
    dir_conf=$3
    solr_port=$4
    ./$dir_name/bin/solr create -c $name -d $dir_conf -shards 1 -replicationFactor 1 -p $solr_port
    echo "Created collection $name"
}

post_documents_solr5() {
    dir_name=$1
    collection=$2
    solr_docs=$3
    solr_port=$4
     # Post documents
    if [ -z "${solr_docs}" ]
    then
        echo "SOLR_DOCS not defined, skipping initial indexing"
    else
        echo "Indexing $solr_docs"
        echo "./$dir_name/bin/post -c $collection $solr_docs -p$solr_port"
        ./$dir_name/bin/post -c $collection $solr_docs -p $solr_port
    fi
}

download_and_run 3.6.0

keep_solr_up

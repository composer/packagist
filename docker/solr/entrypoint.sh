#!/usr/bin/env bash

SOLR_PORT=${SOLR_PORT:-8983}
SOLR_VERSION=${SOLR_VERSION:-4.9.1}
DEBUG=${DEBUG:-false}
SOLR_CORE=${SOLR_CORE:-core0}
# Since Solr 5.x
SOLR_COLLECTION=${SOLR_COLLECTION:-gettingstarted}

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

    java -Djetty.port=$solr_port -Dsolr.solr.home=multicore -jar start.jar &

    wait_for_solr
    cd ../../
    echo "Started"
}

initialize_and_run() {
	version='3.6.0'
    dir_name="apache-solr-${version}"
    dir_conf="conf/"
    add_core $dir_name $dir_conf $SOLR_CORE "$SOLR_CONFS"
    run $dir_name $SOLR_PORT $SOLR_CORE
    echo "skipping initial indexing"
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

initialize_and_run
keep_solr_up

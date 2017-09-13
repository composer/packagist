#!/usr/bin/env bash

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
    run $dir_name $SOLR_PORT $SOLR_CORE
    echo "skipping initial indexing"
}

initialize_and_run
keep_solr_up

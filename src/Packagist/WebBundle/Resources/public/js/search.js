const search = instantsearch({
    appId: '6B7GQC987O',
    apiKey: 'a4b2a9870a8575c9e5c3d2dd00d8de95',
    indexName: 'packagist',
    urlSync: true
});

search.addWidget(
    instantsearch.widgets.hits({
        container: '#hits'
    })
);

search.start();

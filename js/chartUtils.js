export function formatDate(value) {
    if (!(value instanceof Date)) {
        value = new Date(value);
    }
    var month = ('0' + (value.getMonth() + 1)).slice(-2);
    return value.getFullYear() + '-' + month;
}

export function formatDay(value) {
    if (!(value instanceof Date)) {
        value = new Date(value);
    }
    var month = ('0' + (value.getMonth() + 1)).slice(-2);
    var day = ('0' + value.getDate()).slice(-2);
    return value.getFullYear() + '-' + month + '-' + day;
}

export function formatDigit(value) {
    if (!isFinite(value)) {
        return value;
    }
    if (value >= 1000000) {
        return (value / 1000000).toFixed(1) + 'mio';
    }
    if (value >= 1000) {
        return (value / 1000).toFixed(1) + 'K';
    }
    return value;
}

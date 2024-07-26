$(function() {
    var orderStatusURL = $("#moneybadger-payment-iframe").data("moneybadger-status-url");
    var orderValidationURL = $("#moneybadger-payment-iframe").data("moneybadger-validation-url");
    if (!orderStatusURL || !orderValidationURL) {
        return;
    }
    var poller = setInterval(function() {
        $.ajax({
            url: orderStatusURL,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.is_paid === true) {
                    clearInterval(poller);
                    window.location.href = orderValidationURL;
                }
            }
        });
    }, 2000);

});

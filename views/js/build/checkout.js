$(function() {
    var orderStatusURL = $("#moneybadger-payment-iframe").data("moneybadger-status-url");
    var orderConfirmationURL = $("#moneybadger-payment-iframe").data("moneybadger-confirmation-url");

    if (!orderStatusURL || !orderConfirmationURL) {
        return;
    }

    setInterval(function() {
        $.ajax({
            url: orderStatusURL,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.is_paid === true) {
                    window.location.href = orderConfirmationURL;
                }
            }
        });
    }, 5000);

});

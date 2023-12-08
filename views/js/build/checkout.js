$(function() {
    var orderStatusURL = $("#cryptoconvert-payment-iframe").data("cryptoconvert-status-url");
    var orderConfirmationURL = $("#cryptoconvert-payment-iframe").data("cryptoconvert-confirmation-url");

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

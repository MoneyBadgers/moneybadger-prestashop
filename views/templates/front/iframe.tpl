{extends "$layout"}
{block name="content"}
  <section>
    <iframe
      id="moneybadger-payment-iframe"
      src="{$src}"
      width="100%"
      height="700px"
      data-moneybadger-status-url="{$orderStatusURL}"
      data-moneybadger-confirmation-url="{$orderConfirmationURL}"
    ></iframe>
  </section>
{/block}
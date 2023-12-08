{extends "$layout"}
{block name="content"}
  <section>
    <iframe
      id="cryptoconvert-payment-iframe"
      src="{$src}"
      width="100%"
      height="500px"
      data-cryptoconvert-status-url="{$orderStatusURL}"
      data-cryptoconvert-confirmation-url="{$orderConfirmationURL}"
    ></iframe>
  </section>
{/block}
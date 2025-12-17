  </main>
<script>
(function(){
  function pingTimer(){
    try {
      var url = '/admin/timer_tick.php';
      if (navigator.sendBeacon) {
        var blob = new Blob([], {type: 'text/plain'});
        navigator.sendBeacon(url, blob);
      } else {
        fetch(url, {method:'POST', keepalive:true, cache:'no-store', credentials:'include'}).catch(function(){});
      }
    } catch(e) {}
  }
  // initial ping shortly after load, then every 60s while the page is open
  setTimeout(pingTimer, 1500);
  setInterval(pingTimer, 60000);
})();
</script>
</div></body></html>
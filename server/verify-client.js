(() => {
  const state = document.currentScript.dataset.state; const status = document.querySelector('#status');
  document.querySelector('#verify').addEventListener('click', async () => {
    try { if (!window.ethereum) throw new Error('Install or unlock an Ethereum wallet first.'); status.textContent = 'Connecting wallet…';
      const [wallet] = await ethereum.request({ method: 'eth_requestAccounts' });
      if ((await ethereum.request({ method: 'eth_chainId' })).toLowerCase() !== '0x1') throw new Error('Switch your wallet to Ethereum mainnet, then try again.');
      const message = document.querySelector('#message').textContent; const signature = await ethereum.request({ method: 'personal_sign', params: [message, wallet] });
      status.textContent = 'Verifying ownership…'; const r = await fetch('/verify/complete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ state, wallet, signature }) }); const body = await r.json(); if (!r.ok) throw new Error(body.error || 'Verification failed.');
      status.textContent = 'Verified. NFH VERIFIED HOLDER has been added to your Discord account.';
    } catch (e) { status.textContent = e.message || 'Verification failed safely.'; }
  });
})();

/* ===================================================================
   Anuarsh Kit of 5 — Scripts
   Anoopam Ayurveda Pvt. Ltd.
   =================================================================== */

// ── Short helpers ──
const $  = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];

// ── Footer year ──
$('#yr').textContent = new Date().getFullYear();

/* =================================================================
   MOBILE MENU
   ================================================================= */
const menu = $('#menu');

$('#burger').addEventListener('click', () => menu.classList.toggle('open'));

$$('#menu a').forEach((link) => {
  link.addEventListener('click', () => menu.classList.remove('open'));
});

/* =================================================================
   IMAGE GALLERY + LIGHTBOX
   ================================================================= */
const mainImg  = $('#mainImg');
const lightbox = $('#lb');

// Click a thumbnail → swap the main image
$$('#thumbs button').forEach((thumb) => {
  thumb.addEventListener('click', () => {
    $$('#thumbs button').forEach((t) => t.classList.remove('on'));
    thumb.classList.add('on');
    mainImg.src = thumb.querySelector('img').src;
  });
});

// Click main image → open lightbox
$('#main').addEventListener('click', () => {
  $('#lb img').src = mainImg.src;
  lightbox.classList.add('on');
});

// Click anywhere on lightbox or press Esc → close it
lightbox.addEventListener('click', () => lightbox.classList.remove('on'));
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') lightbox.classList.remove('on');
});

/* =================================================================
   ORDER MODAL — open / close
   ================================================================= */
const PRICE = 1780;
const modal = $('#om');
const form  = $('#of');
let   qty   = 1;

// ── Quantity stepper ──
function setQty(value) {
  qty = Math.min(10, Math.max(1, value));
  $('#q').textContent   = qty;
  $('#tot').textContent = '₹' + qty * PRICE;
}
$('#m').addEventListener('click', () => setQty(qty - 1));
$('#p').addEventListener('click', () => setQty(qty + 1));

// ── Open modal ──
function openOrder() {
  form.hidden = false;
  $('#oerr').hidden = true;
  modal.classList.add('on');
  document.body.style.overflow = 'hidden';
  form.elements['name'].focus();
}

// ── Close modal ──
function closeOrder() {
  modal.classList.remove('on');
  document.body.style.overflow = '';
}

// Every "Buy Now" / "Order Now" button opens the form
$$('[data-order]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    menu.classList.remove('open');
    openOrder();
  });
});

$('#omc').addEventListener('click', closeOrder);

// Click outside modal box → close
modal.addEventListener('click', (e) => {
  if (e.target === modal) closeOrder();
});

// Esc key → close
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeOrder();
});

/* =================================================================
   AFTER AN ORDER IS SAVED
   ================================================================= */

// Remember the order for the thank-you page, ask the server to send it to Shipfigo (the page does not wait), then open thank-you
function finishOrder(id, payment) {
  try {
    sessionStorage.setItem('anuarsh_order', JSON.stringify({ id, qty, total: qty * PRICE, payment }));
  } catch (e) { /* storage blocked: the thank-you page still works without it */ }

  fetch('api/ship.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }),
    keepalive: true,
  }).catch(() => {});
  window.location.href = 'thank-you.html?order_id=' + encodeURIComponent(id);
}

// Show an error under the form and let the customer try again
function showOrderError(message) {
  const err = $('#oerr');
  const btn = $('#osub');
  err.textContent = message;
  err.hidden = false;
  btn.disabled = false;
  btn.textContent = 'Place Order';
}

// Open Razorpay checkout for an order the server has created
function openRazorpay(order, data) {
  if (typeof Razorpay === 'undefined') {
    showOrderError('Online payment could not load. Check your internet or choose Cash on delivery.');
    return;
  }

  const rzp = new Razorpay({
    key: order.key_id,
    amount: order.amount,
    currency: 'INR',
    order_id: order.rzp_order_id,
    name: 'Anoopam Ayurveda',
    description: 'Anuarsh Kit of 5 × ' + qty,
    prefill: { name: data.name, contact: data.phone },
    theme: { color: '#0e3b2e' },

    // Payment done → the server checks the signature and marks the order paid
    handler: async (r) => {
      try {
        const res = await fetch('api/verify.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: order.id, ...r }),
        });
        const json = JSON.parse(await res.text());
        if (!json.ok) throw new Error(json.error);
        finishOrder(order.id, 'online');
      } catch (e) {
        showOrderError('Payment received but not confirmed yet. Please contact us with order #' + order.id + '.');
      }
    },

    modal: {
      ondismiss: () => showOrderError('Payment was not completed. You can try again.'),
    },
  });

  rzp.on('payment.failed', (r) => showOrderError(r.error && r.error.description ? r.error.description : 'Payment failed. Please try again.'));
  rzp.open();
}

/* =================================================================
   ORDER FORM — submit → api/order.php
   Cash on delivery → thank-you page.  Pay online → Razorpay → thank-you page.
   ================================================================= */
form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const err = $('#oerr');
  const btn = $('#osub');

  err.hidden = true;
  err.textContent = '';

  // ── Collect data ──
  const data = Object.fromEntries(new FormData(form));
  data.qty = qty;

  // ── Client-side validation ──
  if (
    !data.name.trim() ||
    !/^[6-9]\d{9}$/.test(data.phone) ||
    !data.address.trim() ||
    !data.city.trim() ||
    !data.state ||
    !/^\d{6}$/.test(data.pincode)
  ) {
    showOrderError('Please enter your name, a valid 10-digit mobile number, address, city, state and 6-digit pincode.');
    return;
  }

  // ── Disable button while submitting ──
  btn.disabled = true;
  btn.textContent = 'Placing order…';

  try {
    const res = await fetch('api/order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
      signal: AbortSignal.timeout(20000),   // never wait more than 20 seconds
    });

    // Read as text first so we can show what the server really sent
    const raw = await res.text();
    let json;
    try {
      json = JSON.parse(raw);
    } catch {
      throw new Error(
        'Server problem (HTTP ' + res.status + '): ' +
        raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)
      );
    }
    if (!res.ok || !json.ok) throw new Error(json.error || 'Could not place the order.');

    if (json.online) {
      btn.textContent = 'Waiting for payment…';
      openRazorpay(json, data);          // Cash on delivery skips this
    } else {
      finishOrder(json.id, 'cod');
    }
  } catch (error) {
    showOrderError(
      error.name === 'TimeoutError' ? 'This is taking too long. Please check your internet and try again.' : error.message
    );
  }
});

/* =================================================================
   TABS (Description / What's Inside / Use & Safety)
   ================================================================= */
$$('#tabs button').forEach((tab) => {
  tab.addEventListener('click', () => {
    $$('#tabs button, .panel').forEach((el) => el.classList.remove('on'));
    tab.classList.add('on');
    $('#' + tab.dataset.t).classList.add('on');
  });
});

/* =================================================================
   FAQ ACCORDION (only one open at a time)
   ================================================================= */
$$('.faq button').forEach((question) => {
  question.addEventListener('click', () => {
    const wasOpen = question.classList.contains('open');

    // Close all
    $$('.faq button').forEach((q) => {
      q.classList.remove('open');
      q.lastElementChild.textContent = '+';
    });

    // If it was closed, open it
    if (!wasOpen) {
      question.classList.add('open');
      question.lastElementChild.textContent = '−';
    }
  });
});

/* =================================================================
   FADE-IN ON SCROLL
   ================================================================= */
const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('in');
        observer.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.1 }
);
$$('.fade').forEach((el) => observer.observe(el));

/* =================================================================
   SPLASH SCREEN (fades out after 1.6 s, removed from DOM after 2.8 s)
   ================================================================= */
const splash = $('#splash');
setTimeout(() => splash.classList.add('hide'), 1600);
setTimeout(() => splash.remove(),              2800);
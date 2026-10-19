/* =========================================================
   Ретранслятор заявок для сайта «Империя льда»
   ---------------------------------------------------------
   Нужен, если хостинг не может достучаться до api.telegram.org.
   Сайт отправляет заявку сюда, а этот код пересылает её в Telegram.

   Разворачивается бесплатно на Cloudflare Workers за пять минут:

   1. Зайдите на dash.cloudflare.com, раздел Workers & Pages → Create → Worker.
   2. Дайте имя, например imperia-relay, нажмите Deploy.
   3. Откройте Edit code, вставьте весь этот файл, снова Deploy.
   4. Settings → Variables and Secrets → Add:
        BOT_TOKEN   — токен бота от @BotFather
        CHAT_ID     — id чата, например -1001234567890
        ALLOWED_ORIGIN — адрес сайта, например https://ваш-сайт.ru
      Сохраните.
   5. Скопируйте адрес воркера (вида https://imperia-relay.ваш-логин.workers.dev)
      и впишите его в send.php в переменную $relay_url.

   Токен хранится в переменных Cloudflare и на сайте не лежит.
   ========================================================= */

export default {
  async fetch(request, env) {
    const origin = env.ALLOWED_ORIGIN || '*';

    const cors = {
      'Access-Control-Allow-Origin': origin,
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    };

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: cors });
    }

    if (request.method !== 'POST') {
      return json({ ok: false, description: 'Only POST' }, 405, cors);
    }

    if (!env.BOT_TOKEN || !env.CHAT_ID) {
      return json({ ok: false, description: 'BOT_TOKEN или CHAT_ID не заданы' }, 500, cors);
    }

    // Принимаем и форму, и JSON
    let text = '';
    const type = request.headers.get('content-type') || '';
    try {
      if (type.includes('application/json')) {
        text = (await request.json()).text || '';
      } else {
        text = (await request.formData()).get('text') || '';
      }
    } catch (e) {
      return json({ ok: false, description: 'Не удалось разобрать запрос' }, 400, cors);
    }

    if (!text.trim()) {
      return json({ ok: false, description: 'Пустая заявка' }, 400, cors);
    }

    // Ограничиваем размер, чтобы воркер нельзя было использовать как рассылку
    text = text.slice(0, 2000);

    const tg = await fetch(`https://api.telegram.org/bot${env.BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: env.CHAT_ID,
        parse_mode: 'HTML',
        text,
      }),
    });

    const result = await tg.json();
    return json(result, tg.ok ? 200 : 502, cors);
  },
};

function json(data, status, headers) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8', ...headers },
  });
}

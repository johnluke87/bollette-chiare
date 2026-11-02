export default function Privacy() {
  return (
    <main className="main narrow">
      <h1>Privacy</h1>
      <div className="card stack">
        <p><b>La bolletta in PDF</b> non lascia il tuo dispositivo: il browser ne estrae il testo e manda solo quello al server, che lo passa a Groq (un servizio di intelligenza artificiale) per trovare consumi e prezzi. Né il PDF né il testo vengono salvati.</p>
        <p><b>Senza account</b> non salviamo niente: il confronto si calcola e si dimentica.</p>
        <p><b>Con l’account salviamo</b> nome, email, password (cifrata con hash), e per ogni fornitura: tipo, venditore, offerta, prezzi, POD/PDR se li inserisci, comune, potenza, e per ogni bolletta date, consumi e importi. Niente indirizzo, codice fiscale o IBAN.</p>
        <p><b>Perché.</b> Per mostrarti consumi e confronti e per controllare ogni giorno se esce un’offerta più conveniente per te, mandandoti un avviso per email o Telegram.</p>
        <p><b>Telegram (facoltativo).</b> Se colleghi il bot salviamo solo l’ID della chat. Lo cancelli con “Scollega” o scrivendo /stop al bot.</p>
        <p><b>Fonti.</b> Le offerte sono gli open data del Portale Offerte di ARERA – Acquirente Unico. I comuni vengono dall’elenco ISTAT (comuni-json).</p>
        <p><b>I tuoi diritti.</b> Da Impostazioni puoi esportare tutto in JSON e cancellare l’account: la cancellazione elimina subito forniture e bollette.</p>
        <p><b>Cookie e statistiche.</b> L’accesso usa un token salvato nel browser, non un cookie. Google Analytics si carica solo se lo accetti nel banner dei cookie.</p>
        <p style={{ margin: 0 }}><b>Niente pubblicità, niente commissioni</b> dai venditori e nessuna vendita di dati. Il progetto si sostiene con donazioni volontarie.</p>
      </div>
    </main>
  );
}

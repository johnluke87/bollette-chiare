// Testo di un PDF letto nel browser con pdf.js: al server arriva solo il testo, mai il file.
// pdf.js si carica solo quando serve (è grande).

const MAX_PAGES = 8; // la sintesi e il dettaglio stanno nelle prime pagine

export async function pdfText(file) {
  const pdfjs = await import('pdfjs-dist');
  const { default: workerUrl } = await import('pdfjs-dist/build/pdf.worker.min.mjs?url');
  pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
  const doc = await pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
  const pages = [];
  for (let i = 1; i <= Math.min(doc.numPages, MAX_PAGES); i++) {
    const page = await doc.getPage(i);
    const content = await page.getTextContent();
    pages.push(content.items.map((it) => it.str + (it.hasEOL ? '\n' : ' ')).join(''));
  }
  await doc.destroy();
  return pages.join('\n\n').replace(/[ \t]+/g, ' ').trim();
}

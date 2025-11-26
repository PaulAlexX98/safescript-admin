let recognising = false;
let rec = null;

self.addEventListener('message', (event) => {
    const { action, targetId } = event.data;

    if (action === 'start') {
        const SR = self.SpeechRecognition || self.webkitSpeechRecognition;
        if (!SR) {
            event.ports[0].postMessage({ error: 'Not supported' });
            return;
        }

        rec = new SR();
        rec.lang = 'en-GB';
        rec.continuous = true;
        rec.interimResults = true;

        rec.onresult = (e) => {
            let finalTxt = '';
            for (let i = e.resultIndex; i < e.results.length; i++) {
                if (e.results[i].isFinal) finalTxt += e.results[i][0].transcript;
            }
            if (finalTxt) {
                event.ports[0].postMessage({ text: finalTxt, targetId });
            }
        };

        rec.onerror = (e) => {
            event.ports[0].postMessage({ error: e.error });
        };

        rec.onstart = () => {
            recognising = true;
            event.ports[0].postMessage({ status: 'on' });
        };

        rec.onend = () => {
            recognising = false;
            event.ports[0].postMessage({ status: 'off' });
        };

        rec.start();
    }

    if (action === 'stop') {
        if (rec) rec.stop();
        recognising = false;
        event.ports[0].postMessage({ status: 'off' });
    }
});
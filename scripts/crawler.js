import { chromium } from 'playwright';

async function crawlPage(url, timeout = 60000) {
    let browser;
    try {
        // 브라우저 실행
        browser = await chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu'
            ]
        });

        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        });

        const page = await context.newPage();

        // 타임아웃 설정
        page.setDefaultTimeout(timeout);

        // 페이지 로드
        await page.goto(url, {
            waitUntil: 'domcontentloaded',  // DOM 로드만 기다림
            timeout: 30000
        });

        // 딜량 데이터가 로드될 때까지 스마트 대기
        try {
            // 숫자 패턴이 포함된 요소를 찾을 때까지 최대 10초 대기
            await page.waitForFunction(() => {
                const text = document.body.innerText;
                return /[0-9,]{7,}/.test(text);
            }, { timeout: 10000 });

            console.log('딜량 데이터 로드 완료');
        } catch (e) {
            // 10초 안에 못 찾아도 그냥 진행
            console.log('딜량 데이터 대기 타임아웃, 현재 상태로 진행');
        }

        // 추가 안정성을 위한 2초 대기
        await page.waitForTimeout(2000);

        // HTML 가져오기
        const html = await page.content();

        console.log(html);

    } catch (error) {
        console.error(`크롤링 오류: ${error.message}`);
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

// 명령행 인자 처리
const url = process.argv[2];
const timeout = parseInt(process.argv[3]) || 60000;

if (!url) {
    console.error('URL이 필요함');
    process.exit(1);
}

// 크롤링 실행
crawlPage(url, timeout);

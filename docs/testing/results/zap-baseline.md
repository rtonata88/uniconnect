# UniConnect — OWASP ZAP baseline (passive) scan

ZAP by [Checkmarx](https://checkmarx.com/).


## Summary of Alerts

| Risk Level | Number of Alerts |
| --- | --- |
| High | 0 |
| Medium | 1 |
| Low | 0 |
| Informational | 4 |




## Insights

| Level | Reason | Site | Description | Statistic |
| --- | --- | --- | --- | --- |
| Low | Warning |  | ZAP errors logged - see the zap.log file for details | 9    |
| Low | Warning |  | ZAP warnings logged - see the zap.log file for details | 13    |
| Info | Informational | https://localhost | Percentage of responses with status code 2xx | 69 % |
| Info | Informational | https://localhost | Percentage of responses with status code 4xx | 30 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type application/javascript | 12 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type application/json | 72 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type application/manifest+json | 2 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type image/png | 2 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type image/svg+xml | 2 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type image/x-icon | 2 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type text/css | 2 % |
| Info | Informational | https://localhost | Percentage of endpoints with content type text/html | 4 % |
| Info | Informational | https://localhost | Percentage of endpoints with method GET | 100 % |
| Info | Informational | https://localhost | Count of total endpoints | 47    |




## Alerts

| Name | Risk Level | Number of Instances |
| --- | --- | --- |
| CSP: style-src unsafe-inline | Medium | 3 |
| Information Disclosure - Sensitive Information in URL | Informational | 1 |
| Information Disclosure - Suspicious Comments | Informational | 5 |
| Modern Web Application | Informational | 3 |
| Re-examine Cache-control Directives | Informational | Systemic |




## Alert Detail



### [ CSP: style-src unsafe-inline ](https://www.zaproxy.org/docs/alerts/10055/)



##### Medium (High)

### Description

Content Security Policy (CSP) is an added layer of security that helps to detect and mitigate certain types of attacks. Including (but not limited to) Cross Site Scripting (XSS), and data injection attacks. These attacks are used for everything from data theft to site defacement or distribution of malware. CSP provides a set of standard HTTP headers that allow website owners to declare approved sources of content that browsers should be allowed to load on that page — covered types are JavaScript, CSS, HTML frames, fonts, images and embeddable objects such as Java applets, ActiveX, audio and video files.

* URL: https://localhost
  * Node Name: `https://localhost`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; worker-src 'self'; manifest-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: https://localhost/robots.txt
  * Node Name: `https://localhost/robots.txt`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; worker-src 'self'; manifest-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'`
  * Other Info: `style-src includes unsafe-inline.`
* URL: https://localhost/sitemap.xml
  * Node Name: `https://localhost/sitemap.xml`
  * Method: `GET`
  * Parameter: `Content-Security-Policy`
  * Attack: ``
  * Evidence: `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; worker-src 'self'; manifest-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'`
  * Other Info: `style-src includes unsafe-inline.`


Instances: 3

### Solution

Ensure that your web server, application server, load balancer, etc. is properly configured to set the Content-Security-Policy header.

### Reference


* [ https://www.w3.org/TR/CSP/ ](https://www.w3.org/TR/CSP/)
* [ https://caniuse.com/#search=content+security+policy ](https://caniuse.com/#search=content+security+policy)
* [ https://content-security-policy.com/ ](https://content-security-policy.com/)
* [ https://github.com/HtmlUnit/htmlunit-csp ](https://github.com/HtmlUnit/htmlunit-csp)
* [ https://web.dev/articles/csp#resource-options ](https://web.dev/articles/csp#resource-options)


#### CWE Id: [ 693 ](https://cwe.mitre.org/data/definitions/693.html)


#### WASC Id: 15

#### Source ID: 3

### [ Information Disclosure - Sensitive Information in URL ](https://www.zaproxy.org/docs/alerts/10024/)



##### Informational (Medium)

### Description

The request appeared to contain sensitive information leaked in the URL. This can violate PCI and most organizational compliance policies. You can configure the list of strings for this check to add or remove values specific to your environment.

* URL: https://localhost/api/admin/audit-logs%3Faction=auth.&user_id=1&from=2026-10-01&to=2026-10-31
  * Node Name: `https://localhost/api/admin/audit-logs (action,from,to,user_id)({user_id,from,to,per_page})`
  * Method: `GET`
  * Parameter: `user_id`
  * Attack: ``
  * Evidence: `user_id`
  * Other Info: `The URL contains potentially sensitive information. The following string was found via the pattern: user
user_id`


Instances: 1

### Solution

Do not pass sensitive information in URIs.

### Reference



#### CWE Id: [ 598 ](https://cwe.mitre.org/data/definitions/598.html)


#### WASC Id: 13

#### Source ID: 3

### [ Information Disclosure - Suspicious Comments ](https://www.zaproxy.org/docs/alerts/10027/)



##### Informational (Low)

### Description

The response appears to contain suspicious comments which may help an attacker.

* URL: https://localhost/assets/data-DIenlb-R.js
  * Node Name: `https://localhost/assets/data-DIenlb-R.js`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `from`
  * Other Info: `The following pattern was used: \bFROM\b and was detected in likely comment: "//tinyurl.com/y2uuvskb`};function ge(e,t){this.name=e,this.message=t}m(ge).from(Error).extend({toString:function(){return this.n", see evidence field for the suspicious comment/snippet.`
* URL: https://localhost/assets/index-D7joMTcM.js
  * Node Name: `https://localhost/assets/index-D7joMTcM.js`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `db`
  * Other Info: `The following pattern was used: \bDB\b and was detected in likely comment: "//github.com/date-fns/date-fns/blob/master/docs/unicodeTokens.md`}var Wc=/[yYQqMLwIdDecihHKkms]o|(\w)\1*|''|'(''|[^'])+('|$)|./g", see evidence field for the suspicious comment/snippet.`
* URL: https://localhost/assets/react-Cwr4aY6H.js
  * Node Name: `https://localhost/assets/react-Cwr4aY6H.js`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `select`
  * Other Info: `The following pattern was used: \bSELECT\b and was detected in likely comment: "//www.w3.org/2000/svg`,i);break;case 2:a=s.createElementNS(`http://www.w3.org/1998/Math/MathML`,i);break;default:switch(i){case`", see evidence field for the suspicious comment/snippet.`
* URL: https://localhost/assets/realtime-BA8RPqCI.js
  * Node Name: `https://localhost/assets/realtime-BA8RPqCI.js`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `user`
  * Other Info: `The following pattern was used: \bUSER\b and was detected in likely comment: "//g,`_`).replace(/=+$/,``)}function j(e){return encodeURIComponent(e).replace(/[!'()*]/g,e=>`%`+e.charCodeAt(0).toString(16).toU", see evidence field for the suspicious comment/snippet.`
* URL: https://localhost/assets/ui-C8u3yAT8.js
  * Node Name: `https://localhost/assets/ui-C8u3yAT8.js`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `user`
  * Other Info: `The following pattern was used: \bUSER\b and was detected in likely comment: "//www.w3.org/2000/svg`,viewBox:`0 0 20 20`,fill:`currentColor`,height:`20`,width:`20`,"aria-hidden":`true`},i.createElement(`pat", see evidence field for the suspicious comment/snippet.`


Instances: 5

### Solution

Remove all comments that return information that may help an attacker and fix any underlying problems they refer to.

### Reference



#### CWE Id: [ 615 ](https://cwe.mitre.org/data/definitions/615.html)


#### WASC Id: 13

#### Source ID: 3

### [ Modern Web Application ](https://www.zaproxy.org/docs/alerts/10109/)



##### Informational (Medium)

### Description

The application appears to be a modern web application. If you need to explore it automatically then the Ajax Spider may well be more effective than the standard one.

* URL: https://localhost
  * Node Name: `https://localhost`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<script type="module" crossorigin src="/assets/index-D7joMTcM.js"></script>`
  * Other Info: `No links have been found while there are scripts, which is an indication that this is a modern web application.`
* URL: https://localhost/robots.txt
  * Node Name: `https://localhost/robots.txt`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<script type="module" crossorigin src="/assets/index-D7joMTcM.js"></script>`
  * Other Info: `No links have been found while there are scripts, which is an indication that this is a modern web application.`
* URL: https://localhost/sitemap.xml
  * Node Name: `https://localhost/sitemap.xml`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `<script type="module" crossorigin src="/assets/index-D7joMTcM.js"></script>`
  * Other Info: `No links have been found while there are scripts, which is an indication that this is a modern web application.`


Instances: 3

### Solution

This is an informational alert and so no changes are required.

### Reference




#### Source ID: 3

### [ Re-examine Cache-control Directives ](https://www.zaproxy.org/docs/alerts/10015/)



##### Informational (Low)

### Description

The cache-control header has not been set properly or is missing, allowing the browser and proxies to cache content. For static assets like css, js, or image files this might be intended, however, the resources should be reviewed to ensure that no sensitive content will be cached.

* URL: https://localhost
  * Node Name: `https://localhost`
  * Method: `GET`
  * Parameter: `cache-control`
  * Attack: ``
  * Evidence: `no-cache`
  * Other Info: ``

Instances: Systemic


### Solution

For secure content, ensure the cache-control HTTP header is set with "no-cache, no-store, must-revalidate". If an asset should be cached consider setting the directives "public, max-age, immutable".

### Reference


* [ https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#web-content-caching ](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#web-content-caching)
* [ https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cache-Control ](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cache-Control)
* [ https://grayduck.mn/2021/09/13/cache-control-recommendations/ ](https://grayduck.mn/2021/09/13/cache-control-recommendations/)


#### CWE Id: [ 525 ](https://cwe.mitre.org/data/definitions/525.html)


#### WASC Id: 13

#### Source ID: 3



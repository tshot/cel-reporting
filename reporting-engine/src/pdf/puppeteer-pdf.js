'use strict';

/**
 * puppeteer-pdf.js
 *
 * Generates a PDF from either a URL or an HTML string using Puppeteer.
 *
 * CLI usage:
 *   node puppeteer-pdf.js --url="https://example.com" --output="/tmp/report.pdf"
 *   node puppeteer-pdf.js --html="<h1>Hello</h1>" --output="/tmp/report.pdf"
 *
 * Optional flags:
 *   --format       Page format: A4 (default), Letter, etc.
 *   --landscape    Use landscape orientation (flag, no value needed)
 *   --margin       Margin applied to all sides, e.g. "10mm" (default)
 *   --wait-until   Puppeteer waitUntil: networkidle0 (default), load, domcontentloaded
 *   --timeout      Navigation timeout in ms (default: 30000)
 */

// ---------------------------------------------------------------------------
// Resolve Puppeteer - try local install first, fallback to parent node_modules
// ---------------------------------------------------------------------------
var fs = require('fs');
var os = require('os');
var path = require('path');
var path = require('path');
var puppeteer;
try 
{
    puppeteer = require('puppeteer');
} 
catch (e1) 
{
    try 
    {
        puppeteer = require('../../node_modules/puppeteer');
    } 
    catch (e2) 
    {
        console.error('Puppeteer not found. Run: npm install puppeteer');
        process.exit(1);
    }
}

// ---------------------------------------------------------------------------
// Argument parser (no external dependencies)
// ---------------------------------------------------------------------------
function parseArgs(argv) 
{
    var args = {};
    argv.slice(2).forEach(function(arg) 
    {
        var match = arg.match(/^--([^=]+)(?:=(.*))?$/);
        if (match) {
            args[match[1]] = match[2] !== undefined ? match[2] : true;
        }
    });
    return args;
}

// ---------------------------------------------------------------------------
// Build Puppeteer PDF option object
// ---------------------------------------------------------------------------
function buildPdfOptions(outputPath, options) 
{
    var margin;
    if (options.margin) {
        margin = {
            top:    options.margin,
            right:  options.margin,
            bottom: options.margin,
            left:   options.margin
        };
    } 
    else 
    {
        margin = {
            top:    '10mm',
            right:  '10mm',
            bottom: '10mm',
            left:   '10mm'
        };
    }

    return {
        path:            outputPath,
        format:          options.format || 'A4',
        landscape:       options.landscape === true || options.landscape === 'true',
        printBackground: options.printBackground !== false,
        margin:          margin
    };
}

// ---------------------------------------------------------------------------
// Launch browser helper
// ---------------------------------------------------------------------------
function launchBrowser() 
{
    var opts = {
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-crashpad',
            '--user-data-dir=' + path.join(__dirname, '..', '..', 'var', 'chromium')]
    };

    // Use the system Chrome when it exists (dev machines); otherwise fall
    // back to the Chromium that npm install placed under ./chrome, which is
    // what production has. Setting executablePath to a missing binary is a
    // hard failure, so it is only set when the file is actually present.
    if (fs.existsSync('/usr/bin/google-chrome')) {
        opts.executablePath = '/usr/bin/google-chrome';
    }

    // Chromium's crashpad handler needs a writable HOME, and it will not
    // accept the same path as --user-data-dir. Under Apache the browser runs
    // as www-data, whose HOME is /var/www and is not writable, so crashpad
    // fails and the launch aborts. The directory is namespaced per user
    // because the first user to run it creates the subdirectories mode 700
    // and every other user then fails -- on dev this script is run both as
    // the developer and, via Apache, as www-data. Created on demand so no
    // deployment step is needed.
    var chromeHome = path.join(
        __dirname, '..', '..', 'var',
        'chromium-home-' + (os.userInfo().username || 'default')
    );
    fs.mkdirSync(chromeHome, { recursive: true });
    process.env.HOME = chromeHome;

    return puppeteer.launch(opts);
}

// ---------------------------------------------------------------------------
// Generate PDF from a URL
// ---------------------------------------------------------------------------
async function generateFromUrl(url, outputPath, options) 
{
    options = options || {};
    var browser = await launchBrowser();
    try 
    {
        var page = await browser.newPage();
        await page.goto(url, 
        {
            waitUntil: options.waitUntil || 'networkidle0',
            timeout:   options.timeout  || 30000
        });
        await page.pdf(buildPdfOptions(outputPath, options));
    } 
    finally 
    {
        await browser.close();
    }
}

// ---------------------------------------------------------------------------
// Generate PDF from an HTML string
// ---------------------------------------------------------------------------
async function generateFromHtml(html, outputPath, options) 
{
    options = options || {};
    var browser = await launchBrowser();
    try 
    {
        var page = await browser.newPage();
        await page.setContent(html, 
        {
            waitUntil: options.waitUntil || 'networkidle0',
            timeout:   options.timeout  || 30000
        });
        await page.pdf(buildPdfOptions(outputPath, options));
    } 
    finally 
    {
        await browser.close();
    }
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------
async function main() 
{
    var args   = parseArgs(process.argv);
    var url    = args.url    || null;
    var html   = args.html   || null;
    var output = args.output || null;

    if (!output) 
    {
        console.error('Error: --output path is required.');
        process.exit(1);
    }

    if (!url && !html) 
    {
        console.error('Error: provide either --url or --html.');
        process.exit(1);
    }

    var pdfOptions = {
        format:    args.format         || 'A4',
        landscape: args.landscape      || false,
        margin:    args.margin         || null,
        waitUntil: args['wait-until']  || 'networkidle0',
        timeout:   args.timeout        ? parseInt(args.timeout, 10) : 30000
    };

    try 
    {
        if (url) 
        {
            await generateFromUrl(url, output, pdfOptions);
        } 
        else 
        {
            await generateFromHtml(html, output, pdfOptions);
        }
        console.log('PDF saved to: ' + output);
        process.exit(0);
    } 
    catch (err) 
    {
        console.error('PDF generation failed: ' + err.message);
        process.exit(1);
    }
}

// Run as CLI or export for programmatic use
if (require.main === module) 
{
    main();
} 
else 
{
    module.exports = {
        generateFromUrl:  generateFromUrl,
        generateFromHtml: generateFromHtml
    };
}

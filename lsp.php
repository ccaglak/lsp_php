#!/usr/bin/env php
<?php

$opts = getopt("c:fhi:l::", ['check-cmds:', 'full-sync', 'help', 'include-path:', 'log::']);

if (isset($opts['h']) || isset($opts['help']))
{
	fputs(STDERR, <<<USAGE
Usage: $argv[0] [OPTIONS]
Options:
  -c=CMDS, --check-cmds=CMDS    Run these syntax check / lint commands on code
  -f,      --full-sync          Use full instead of incremental sync
  -h,      --help               Show usage
  -i=PATH, --include-path=PATH  Prepend this path to the include path
  -l=FILE, --log=FILE           Enable logging to file FILE

USAGE);
	exit(1);
}

if ($include_path = $opts['i'] ?? $opts['include-path'])
	ini_set('include_path', "$include_path:" . ini_get('include_path'));

$checkcmds = $opts['c'] ?? $opts['check-cmds'] ?? 'php -nl;phplint.php -f';

$log = fopen(($logfile = $opts['l'] ?? $opts['log'] ?? '/dev/null') ? $logfile : (getenv('HOME') . '/.cache/helix/lsp_php.log'), 'a');
$state = 'new';
$len = null;
$documents = [];

while (!feof(STDIN))
{
	$line = fgets(STDIN, $len);
	fputs($log, "$state($len): $line");

	[$state, $len, $req] = match($state) {
		'new'  => ['nl',   preg_match('/Content-Length: (\d+)/', $line, $m) ? ($m[1] + 1) : null, null],
		'nl'   => ['read', $len, null],
		'read' => ['new',  null, json_decode($line)],
	};

	if ($req)
	{
		unset($result, $error);

		@['result' => $result, 'error' => $error] = match($req->method) {
			'initialize' => [
				'result' => [
					'capabilities' => [
						'positionEncoding'       => 'utf-8',
						'textDocumentSync'       => (isset($opts['f']) || isset($opts['full-sync'])) ? 1 : 2,  # 1=Full, 2=Incremental
						'implementationProvider' => true,
						'documentSymbolProvider' => true,
						'hoverProvider'          => true,
					],
				],
			],

			'textDocument/didOpen' => [
				'void' => $documents[$req->params->textDocument->uri] = $req->params->textDocument->text,
			],

			'textDocument/didChange' => [
				'void' => $documents[$req->params->textDocument->uri] = array_reduce(
					$req->params->contentChanges,
					fn($d, $v) => $v->range
						? (substr($d, 0, offset($d, $v->range->start)) . $v->text . substr($d, offset($d, $v->range->end)))
						: $v->text,
					$documents[$req->params->textDocument->uri]
				),
			],

			'textDocument/didClose' => [
				'void' => $documents[$req->params->textDocument->uri] = null,
			],

			'textDocument/documentSymbol' => [
				'result' => symbols($documents[$req->params->textDocument->uri]),
			],

			'textDocument/implementation', 'textDocument/hover' => [
				'result' => symbol($documents[$req->params->textDocument->uri], $req),
			],

			'shutdown', 'exit' => [],

			default => [
				'error' => [
					'code'    => -32601,  # MethodNotFound
					'message' => 'Method not found',
				],
			],
		};

		$response = @json_encode([
			'id'     => $req->id,
			'result' => $result,
			'error'  => $error,
		]);

		if (isset($req->id))
		{
			fputs(STDOUT, "Content-Length: " . strlen($response) . "\r\n\r\n" . $response);
			# fputs($log, "Content-Length: " . strlen($response) . "\r\n\r\n" . $response);
			fflush(STDOUT);
		}
		# else
		#   fputs($log, "\n\nDOC '$document'\n\n");

		# Run syntax checkers and lints on changed/opened documents
		if ($req->method == 'textDocument/didChange' || $req->method == 'textDocument/didOpen')
		{
			$diagnostics = [];

			foreach (explode(';', $checkcmds) as $checkcmd)
			{
				$pipes = [];
				if ($check = proc_open($checkcmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes))
				{
					fputs($pipes[0], $documents[$req->params->textDocument->uri]);
					fclose($pipes[0]);

					$checkoutput = stream_get_contents($pipes[1]);
					fclose($pipes[1]);
					fclose($pipes[2]);

					foreach (explode("\n", $checkoutput) as $line)
					{
						# PHP Parse error:  syntax error, unexpected token "%", expecting end of file in Standard input code on line 3
						# t.php:28 $d used only once: $d = 42;
						if (preg_match('/^[^:]+:\s+(?<message>.*) in Standard input code on line (?<line>\d+)/', $line, $checkmatches) ||
						    preg_match('/^\S+:(?<line>\d+):\d+\s+(?<message>.*)/', $line, $checkmatches))
						{
							['line' => $checkline, 'message' => $checkmessage] = $checkmatches;
							$diagnostics[] = [
								'range'   => ['start' => ['line' => $checkline - 1, 'character' => 0], 'end' => ['line' => $checkline - 1, 'character' => 0]],
								# 'severity' => $severity,
								'message' => $checkmessage,
							];
						}
					}

					proc_close($check);
				}
			}

			$response = json_encode([
				'method' => 'textDocument/publishDiagnostics',
				'params' => [
					'uri'    => $req->params->textDocument->uri,
					'diagnostics' => $diagnostics,
				],
			]);

			fputs(STDOUT, "Content-Length: " . strlen($response) . "\r\n\r\n" . $response);
			# if ($diagnostics)
			# 	fputs($log, "Content-Length: " . strlen($response) . "\r\n\r\n" . $response);
		}

		if ($req->method == 'exit')
			exit(0);
	}
}

# Helper function
function symbols($document)
{
	# To generate symbol table
	$symbols = [];
	$line = $col = 0;
	$functiondef = false;

	$result = array_values(array_filter(array_map(function($token) use (&$functiondef, &$line, &$col, $symbols) {
		if ($line != intval($token[2]) - 1)
			[$line, $col] = [intval($token[2]) - 1, 0];

		switch ($token[0])
		{
			case T_WHITESPACE: case T_DOC_COMMENT: case T_COMMENT:
				break;

			case T_FUNCTION:
				$functiondef = true;
				break;

			case T_STRING:
				if ($functiondef)
				{
					return [
						'name' => $token[1],
						'kind' => 12,  # Function
						'range' => [
							'start' => ['line' => $line, 'character' => $col],
							'end'   => ['line' => $line, 'character' => $col + strlen($token[1])],
						],
						'selectionRange' => [
							'start' => ['line' => $line, 'character' => $col],
							'end'   => ['line' => $line, 'character' => $col + strlen($token[1])],
						],
					];
				}

			default:
				$functiondef = false;
				break;
		}

		$col += strlen($token[1]);
		return null;
	}, token_get_all((string)$document))));

	return $result;
}

function identifier($document, $position)
{
	$start = $end = offset($document, $position);

	while ($start > 0 && preg_match('/[\w:]/u', $document[$start - 1]))
		$start--;

	while ($end < strlen($document) && preg_match('/[\w:]/u', $document[$end]))
		$end++;

	return substr($document, $start, $end - $start);
}

function offset($document, $position)
{
	['line' => $line, 'character' => $character] = (array)$position;

	for ($start = 0; $line > 0 && $start !== false; $line--)
		$start = strpos($document, "\n", $start) + 1;  # Byte offsets!
	$start = $start + $character;

	return $start;
}

function symbol($document, $req)
{
	$identifier = identifier($document, $req->params->position);
	$name = preg_replace('/^\w+::/', '', $identifier);

	try { $funcref = new ReflectionMethod($identifier);   } catch (Exception) {}
	try { $funcref = new ReflectionFunction($identifier); } catch (Exception) {}

	if ($funcref)
	{
		if ($filename = $funcref->getFileName())
		{
			$document = file_get_contents($filename);
			$uri = "file://$filename";
			$range =  array_values(array_filter(symbols($document), fn($v) => $name === $v['name']))[0]['range'];
		}

		$doccomment = $funcref->getDocComment();
		$contents = trim('***' .
			($funcref->isStatic ? 'static ' : '') .
			# 'function ' .
			"$identifier(" .
			implode(', ', array_map(fn($v) =>
				($v->isVariadic() ? '...' : '') .
				($v->allowsNull ? '?' : '') .
				trim($v->getType() . ' $' . $v->getName()) .
				($v->isDefaultValueAvailable() ? (' = ' . ($v->isDefaultValueConstant() ? $v->getDefaultValueConstantName() : json_encode($v->getDefaultValue()))) : ''),
				$funcref->getParameters()
			)) .
			') : ' . ($funcref->getReturnType() ?: '?') .
			"***\n\n" .
			preg_replace('!^[\s*/]*!m', '', $doccomment)
		);
	}
	else
	{
		$uri = $req->params->textDocument->uri;
		$range =  array_values(array_filter(symbols($document), fn($v) => $name === $v['name']))[0]['range'];
		$contents = explode("\n", $document)[$range['start']['line']];  # Whole line of function definition
	}

	return [
		'uri' => $uri,
		'range' => $range,
		'contents' => $contents,
	];
}
<?php

namespace Fhp\Parser;

use Fhp\Parser\Exception\MT940Exception;

/**
 * Class MT940
 *
 * See https://www.kontopruef.de/mt940s.shtml for field documentation
 *
 * @package Fhp\Parser
 */
class MT940
{
	const TARGET_ARRAY = 0;

	const CD_CREDIT = 'credit';
	const CD_DEBIT = 'debit';
	const CD_CREDIT_CANCELLATION = 'credit_cancellation';
	const CD_DEBIT_CANCELLATION = 'debit_cancellation';

	// The divider can be either \r\n or @@
	const LINE_DIVIDER = "(@@|\r\n)";

	/** @var string */
	protected $rawData;
	/** @var string */
	protected $soaDate;

	/**
	 * MT940 constructor.
	 *
	 * @param string $rawData
	 */
	public function __construct($rawData)
	{
		$this->rawData = (string) $rawData;
	}

	/**
	 * @param string $target
	 * @return array
	 * @throws MT940Exception
	 */
	public function parse($target)
	{
		switch ($target) {
		case static::TARGET_ARRAY:
			return $this->parseToArray();
			break;
		default:
			throw new MT940Exception('Invalid parse type provided');
		}
	}

	/**
	 * @return array
	 * @throws MT940Exception
	 */
	protected function parseToArray()
	{
		$result = array();

		// split at every :20: ("Die Felder ":20:" bis ":28:" müssen vor jedem Zwischensaldo ausgegeben werden.")
		$statementBlocks = preg_split('/' . self::LINE_DIVIDER . ':20:.*?' . self::LINE_DIVIDER . '/', $this->rawData);

		foreach ($statementBlocks as $statementBlock) {
			$parts = preg_split('/' . self::LINE_DIVIDER . ':/', $statementBlock);
			$statement = array();
			$transactions = array();
			$cnt = 0;
			for ($i = 0, $cnt = count($parts); $i < $cnt; $i++) {
				// handle start balance
				// 60F:C160401EUR1234,56
				if (preg_match('/^60[FM]:/', $parts[$i])) {
					$parts[$i] = substr($parts[$i], 4);
					$this->soaDate = $this->getDate(substr($parts[$i], 1, 6));

					$amount = str_replace(',', '.', substr($parts[$i], 10));
					$statement['start_balance'] = array(
									'amount' => $amount,
									'credit_debit' => (substr($parts[$i], 0, 1) == 'C') ? static::CD_CREDIT : static::CD_DEBIT
							);
					$statement['date'] = $this->soaDate;
				} elseif (
							// found transaction
							// trx:61:1603310331DR637,39N033NONREF
							0 === strpos($parts[$i], '61:')
							&& isset($parts[$i + 1])
							&& 0 === strpos($parts[$i + 1], '86:')
					) {
					$transaction = substr($parts[$i], 3);
					$description = substr($parts[$i + 1], 3);

					$currentTrx = array();

					$currentTrx['turnover_raw'] = ':' . $parts[$i];
					$currentTrx['multi_purpose_raw'] = ':' . $parts[$i + 1];

					preg_match('/^\d{6}(\d{4})?(C|D|RC|RD)[A-Z]?([^N]+)N/', $transaction, $matches);

					switch ($matches[2]) {
							case 'C':
									$currentTrx['credit_debit'] = static::CD_CREDIT;
									break;
							case 'D':
									$currentTrx['credit_debit'] = static::CD_DEBIT;
									break;
							case 'RC':
									$currentTrx['credit_debit'] = static::CD_CREDIT_CANCELLATION;
									break;
							case 'RD':
									$currentTrx['credit_debit'] = static::CD_DEBIT_CANCELLATION;
									break;
							default:
									throw new MT940Exception('c/d/rc/rd mark not found in: ' . $transaction);
							}

					$amount = $matches[3];
					$amount = str_replace(',', '.', $amount);
					$currentTrx['amount'] = floatval($amount);

					$currentTrx['transaction_code'] = substr($description, 0, 3);

					$description = $this->parseDescription($description);
					$currentTrx['description'] = $description;

					// :61:1605110509D198,02NMSCNONREF
					// 16 = year
					// 0511 = valuta date
					// 0509 = booking date
					$year = substr($transaction, 0, 2);
					$valutaDate = $this->getDate($year . substr($transaction, 2, 4));
					$bookingDateMonthDay = substr($transaction, 6, 4);

					$currentTrx['booking_date'] = $this->determineBookingDate($year, $valutaDate, $bookingDateMonthDay);
					$currentTrx['valuta_date'] = $valutaDate;

					$transactions[] = $currentTrx;
				}
			}
			$statement['transactions'] = $transactions;
			if (count($transactions) > 0 && array_key_exists('start_balance', $statement)) {
				// $statement['start_balance'] is not set for earmarked transaction blocks
				$result[] = $statement;
			}
		}

		return $result;
	}

	protected function determineBookingDate($year, $valutaDate, $bookingDateMonthDay)
	{
		$result = null;
		if (!preg_match('/^\d{4}$/', $bookingDateMonthDay)) {
			// if booking date not set in :61, then we have to take it from :60F
			return $this->soaDate;
		}
		// see https://github.com/mschindler83/fints-hbci-php/issues/101
		$vd_date = new \DateTime($valutaDate);
		$minDiff = null;
		for ($bookingYear = $year - 1; $bookingYear <= $year + 1; $bookingYear++) {
			$bd = $this->getDate($bookingYear . $bookingDateMonthDay);
			$bd_date = new \DateTime($bd);
			$diff = $bd_date->diff($vd_date)->days;
			if ($diff === 0) {
				return $bd; // shortcut
			} elseif (null === $minDiff || $diff < $minDiff) {
				$minDiff = $diff;
				$result = $bd;
			}
		}
		return $result;
	}

	protected function parseDescription($descr)
	{
		// Geschäftsvorfall-Code
		$gvc = substr($descr, 0, 3);

		$prepared = array();
		$result = array();

		// prefill with empty values
		for ($i = 0; $i <= 63; $i++) {
			$prepared[$i] = null;
		}

		$descr = preg_replace('/' . self::LINE_DIVIDER . '/', '', $descr);
		$descr = preg_replace('/  +/', ' ', $descr);
		$descr = str_replace('? ', '?', $descr);
		preg_match_all('/\?[\r\n]*(\d{2})([^\?]+)/', $descr, $matches, PREG_SET_ORDER);

		$descriptionLines = array();
		$description1 = ''; // Legacy, could be removed.
		$description2 = ''; // Legacy, could be removed.
		foreach ($matches as $m) {
			$index = (int) $m[1];
			if ((20 <= $index && $index <= 29) || (60 <= $index && $index <= 63)) {
				if (20 <= $index && $index <= 29) {
					$description1 .= $m[2];
				} else {
					$description2 .= $m[2];
				}
				$m[2] = trim($m[2]);
				if (!empty($m[2])) {
					$descriptionLines[] = $m[2];
				}
			} else {
				$prepared[$index] = $m[2];
			}
		}

		$description = $this->extractStructuredDataFromRemittanceLines($descriptionLines, $gvc, $prepared);

		$result['booking_code']      = $gvc;
		$result['booking_text']      = trim($prepared[0]);
		$result['description']       = $description;
		$result['primanoten_nr']     = trim($prepared[10]);
		$result['description_1']     = trim($description1);
		$result['bank_code']         = trim($prepared[30]);
		$result['account_number']    = trim($prepared[31]);
		$result['name']              = trim($prepared[32] . $prepared[33]);
		$result['text_key_addition'] = trim($prepared[34]);
		$result['description_2']     = $description2;
		$result['desc_lines']        = $descriptionLines;

		return $result;
	}

	/**
	 * @param string[] $lines that contain the remittance information
	 * @param string $gvc Geschätsvorfallcode; Out-Parameter, might be changed from information in remittance info
	 * @param string $rawLines All the lines in the Multi-Purpose-Field 86; Out-Parameter, might be changed from information in remittance info
	 * @return array
	 */
	protected function extractStructuredDataFromRemittanceLines($descriptionLines, &$gvc, &$rawLines)
	{
		$description = array();
		if (empty($descriptionLines) || strlen($descriptionLines[0]) < 5 || $descriptionLines[0][4] !== '+') {
			$description['SVWZ'] = implode('', $descriptionLines);
		} else {
			$lastType = null;
			foreach ($descriptionLines as $line) {
				if (strlen($line) >= 5 && $line[4] === '+') {
					if ($lastType != null) {
						$description[$lastType] = trim($description[$lastType]);
					}
					$lastType = substr($line, 0, 4);
					$description[$lastType] = substr($line, 5);
				} else {
					$description[$lastType] .= $line;
				}
				if (strlen($line) < 27) {
					// Usually, lines are 27 characters long. In case characters are missing, then it's either the end
					// of the current type or spaces have been trimmed from the end. We want to collapse multiple spaces
					// into one and we don't want to leave trailing spaces behind. So add a single space here to make up
					// for possibly missing spaces, and if it's the end of the type, it will be trimmed off later.
					$description[$lastType] .= ' ';
				}
			}
			$description[$lastType] = trim($description[$lastType]);
		}

		return $description;
	}

	/**
	 * @param string $val
	 * @return string
	 */
	protected function getDate($val)
	{
		$val = '20' . $val;
		preg_match('/(\d{4})(\d{2})(\d{2})/', $val, $m);
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}
}

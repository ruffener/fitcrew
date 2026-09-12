<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__).'/inc/product/transaction.php';
/** Procedural test double only; does not claim MySQL locking/transaction integration proof. */
final class FamilyAlphaTransactionSpy extends PDO
{
    public bool $active=false;
    public array $calls=[];
    public function __construct() {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->active=true; $this->calls[]='BEGIN'; return true; }
    public function commit(): bool { $this->active=false; $this->calls[]='COMMIT'; return true; }
    public function rollBack(): bool { $this->active=false; $this->calls[]='ROLLBACK'; return true; }
    public function exec(string $statement): int|false { $this->calls[]=$statement; return 0; }
}
function family_tx_assert(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); }
$pdo=new FamilyAlphaTransactionSpy();
family_tx_assert(fc_product_atomic($pdo,fn()=>42)===42,'Return value lost.');
family_tx_assert($pdo->calls===['BEGIN','COMMIT'],'Standalone successful operation must own its commit.');
$pdo->calls=[];
try { fc_product_atomic($pdo,fn()=>throw new RuntimeException('expected')); } catch (RuntimeException $error) { if ($error->getMessage()!=='expected') throw $error; }
family_tx_assert($pdo->calls===['BEGIN','ROLLBACK'],'Standalone failure must roll back.');
$pdo->active=true;$pdo->calls=[];
fc_product_atomic($pdo,fn()=>null);
family_tx_assert(count($pdo->calls)===2 && str_starts_with($pdo->calls[0],'SAVEPOINT fc_product_') && str_starts_with($pdo->calls[1],'RELEASE SAVEPOINT fc_product_'),'Nested success needs a savepoint/release, not an outer commit.');
$pdo->calls=[];
try { fc_product_atomic($pdo,fn()=>throw new RuntimeException('expected')); } catch (RuntimeException $error) { if ($error->getMessage()!=='expected') throw $error; }
family_tx_assert($pdo->active && count($pdo->calls)===3 && str_starts_with($pdo->calls[1],'ROLLBACK TO SAVEPOINT fc_product_'),'Nested failure must preserve caller transaction.');
family_tx_assert(fc_product_current_read($pdo)===' FOR UPDATE','Write decisions need current reads.');
$pdo->active=false;family_tx_assert(fc_product_current_read($pdo)==='','Ordinary reads must not acquire write locks.');
fwrite(STDOUT,"Family Alpha transaction orchestration unit proof: PASS\n- Standalone commit/rollback ownership: PASS\n- Caller-owned transaction savepoint/release/rollback: PASS\n- Current-read suffix only inside a transaction: PASS\n- Database transaction/locking execution remains a separate integration proof.\n");

<?php
$checkout=$argv[1]; $baseline=$argv[2] ?? null;
require $checkout.'/vendor/autoload.php';
if ($baseline) {
 require $baseline.'/app/Domain/Contracts/ContractIssuancePolicy.php';
 require $baseline.'/app/Domain/Contracts/ContractRenderProfile.php';
}
$profile=App\Domain\Contracts\ContractRenderProfile::current($baseline ?? $checkout);
$rendered=(new App\Domain\Contracts\TcpdfContractRenderer)->render(Tests\Support\ContractRendererFixtures::input(),$profile);
echo json_encode(['profile'=>$profile,'profile_hash'=>App\Domain\Contracts\ContractRenderProfile::hash($profile),'pdf_sha256'=>$rendered->sha256,'size_bytes'=>$rendered->sizeBytes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";

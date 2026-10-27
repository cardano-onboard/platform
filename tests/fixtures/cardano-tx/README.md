# A real mainnet transaction

`both-validity-bounds.hex` is the CBOR of mainnet transaction `449ba9c16159114bdc285a75fb6926c385327945ba05ed5e05fd992d4b6ab3b7` (epoch 655, block 13944985), fetched from Koios `POST /tx_cbor`. It carries both a lower and an upper validity bound and spends its inputs exactly, with no change.

It is the fixture of the same name in the `cardano-php/transaction` package's serialization corpus, copied here whole because the published package does not ship its tests. `InvariantGateTest` decodes it and perturbs one field at a time to prove each of the gate's refusals.

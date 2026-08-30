<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * Two RSA pairs, frozen so the suite does not spend a keygen per process, and so a rotation test
 * has a second identity to rotate to. They are test material and nothing else: whatever else these
 * bytes are used for is the fault of whoever used them.
 */
final class SigningKeys
{
    public const string SECRET = 'a-shared-secret-that-signs-and-verifies';

    public const string ROTATED_SECRET = 'the-secret-that-came-after-it';

    public const string PRIVATE_KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC4JAE2noDUaSvc
        QtrTUAQ+i5CI0T58XGNqc1ijTpgzsc0/jgbkfE25q7Js7StDzTmehhP/LoMo3yX/
        xL3l5WCnPUG9zf5NzykpHbBczXCG10aCytkOlspQHRH0lsFq/rFC8+CUUIW0divb
        pybIk5HGrxMVRmXWbwvZ0qtVf6H7MCftm3WIZftxTAKbSMQOMp9XTMDqHYp8tHCZ
        kvr/cbDiShzE+K+T8GLXYtkWVzdhktVtDawhBjeNRkhG0tvomTZH4GEMHXE36LTg
        xkJgSxLK9iVhMC0npe28yEMdpvDr4QiJ2LQ3twxGoXh7DUYqt3nnDOx3nGrDjRgx
        b/SEE20rAgMBAAECggEACQsHnjmRhRz3IPWNjpQe6T1sZzOzeGMHJNquTzLUabGB
        LW5Zq03peUVT2WKaXdWNz1mxULZljZPL53AvjUNDCGOLP3mG1CZo1JKXLy+Np6mZ
        1mGE4GEKZX3P/G2M6SbB6NJWRKJhtpeZFsvyLSaGIbZGXySaaroAYH2mmWfPuiix
        OSiJrRpS7D3MNKNHDJ3OrsJgADgdwMNxnU6G09Hk8Sko2EUPZmSFnkMzDGKUGA2I
        2HUdXI9U9F2USuXrDVUE+r1ux9Wa1ysC7CuUVS86dwutbpSD8npwj49ywW5vN1w/
        N4YNmjBc8UIwVf9qvr4bz0D8ch8xNg4QFTAezn719QKBgQD6yIvN8PSXCqNxpmVj
        bwZQ5vRdFtvE3CUferwfVaXskSjciDMwCwqA6XeZSkimeAUSbX+4eYGhmRR4oSah
        qif0I28Daen5EJWBw3YDT5KadQQQyKmvqfQDuJu4s8LUOhPR/aY2R+LGRN+67Y9W
        CXfrzc3wtwf7WTuRWHE3JGlO9wKBgQC7+JPS49dcUo6ZwDvkB9HOQSxiL+lx4YdL
        BAXlOGTSMTnobkDFnQchJCPbaSN8nn9VUCHAdNXf6RiVlaseL6Rq7QKk64vTFglr
        QWKAJd+XiI/FRhYO2eQZ0edlKJz9zzwBocVaUsen6T98ZPxjNjf+nn32nQOvdxpG
        cJ9Kv0AibQKBgEzRn5mO5K1bseM/UDFcMfgYNuRI+zrbIHf7FaMXjkLf2D9tbRib
        WTVRzrPjAEwV2Z/icMwmVCIXDSFCY94DjEeJjxjhma0UemeMYxryhfrQO1WU0f2g
        NsHpC7JRRi3SOH4Lj51y+bE4KbxNxqlZLyXJHftNZaGFnOyRxeRZP/TTAoGAar9W
        I7OkgBeaSBJ1cKBIM9urOu9+oV+0l5NnTa9jAkNWYXsLaa4teFKv0lC5CHJyWZ6y
        LDutogUcIwbmMLRZqSeEEuh5dZzUKIbvS1s2yTWSgDO3HyP6d/dOc5JG5ZSkvUCD
        dTIBlIfPt/MZqbYGwqB7ZGvyxdjboRkgPTCzWVkCgYB66Fod2Ym0uHNrsh6/nTUS
        RhUlBWIgvio0qhQCvpS6CggrYYIxrRfI5sT2GGra9qMxpkCJvFa8eoRW2WWvTPcN
        /QVDe2th0PCjVJtkxewbfETgUFmMcUR/Fb+P511NujEB3FktcavByM9h/+/QYgau
        L8FTsIwDt2tZSuWnU7GcSw==
        -----END PRIVATE KEY-----
        PEM;

    public const string PUBLIC_KEY = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuCQBNp6A1Gkr3ELa01AE
        PouQiNE+fFxjanNYo06YM7HNP44G5HxNuauybO0rQ805noYT/y6DKN8l/8S95eVg
        pz1Bvc3+Tc8pKR2wXM1whtdGgsrZDpbKUB0R9JbBav6xQvPglFCFtHYr26cmyJOR
        xq8TFUZl1m8L2dKrVX+h+zAn7Zt1iGX7cUwCm0jEDjKfV0zA6h2KfLRwmZL6/3Gw
        4kocxPivk/Bi12LZFlc3YZLVbQ2sIQY3jUZIRtLb6Jk2R+BhDB1xN+i04MZCYEsS
        yvYlYTAtJ6XtvMhDHabw6+EIidi0N7cMRqF4ew1GKrd55wzsd5xqw40YMW/0hBNt
        KwIDAQAB
        -----END PUBLIC KEY-----
        PEM;

    public const string ROTATED_PRIVATE_KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDBzqQz8WUnNjNS
        FDX/W5F+6TFmSeEnAhtvo+fX8l8PDwWucuA8Xpn2WA8nRBhg1FC06lC25eOfM8eC
        5SOrmPtJju5qxxLpoZWg1zeMe06JTOjGKp8+rz6OXtCl8wyfQ/3zylsA0/fUfbon
        k1ZhDdxNBZC61/YHcP9VaDu/m2Zqq7jOb/jygmz0j2TktiGZEH4ElslIdXW2MDmG
        z6BChDg2+5E1sVjhuk2ONLAKt+V4EwJHMLyplnr/cOacY7wmO0nYCrJ0P1gfBPGn
        7mOTaHQvKL9JSp4OUVjEQhUJh99IQ8520k/TkjA+1PCyxUOq3AjKVKo+dGtrJHMV
        tYsO8MdlAgMBAAECggEAXDptmshn9jKNVqSGk8gsI4RufTpwOoN+sfCCjpnpEb34
        2q0RN7lfNENwpqN5pG61H3sYQQmCeksGSSyo/mqVPsqVe9vTjLnX/kwcw64UUDN4
        3IEA+jAkKVVGnopcudf93IuyJeE5YXYZZebwJsyVR1P8LWZDTwQ/hhHNyR93LBBK
        gFrlR9Ybp2pjM0qBWToyDV31lJJTBq83GLNPG7CCOw+hER01Y7SVYqNSoRG97i4g
        XHm/Vv4vc9Asb0j9R3zyGfiSF2zMBIInTTcnBqjj0u7Lhal5OhqkO6fXc2Tf93CF
        DnIx5csosfqBRfnfXhZsCDVeCC6tUuU/E23123FymwKBgQDw+yKvIzj0QFBtvyMK
        jhsk9OfGMhK5zPGc2MvhhtDEyGJGsRXIgRy2gRCao6EDog/5L/tQRHkc7ffbDb0y
        TuMYyFuZ32dRreyvJ9Hy6rTg7IjeYIGRQSjEatNGm+ajrVrKp8TgJX34TmUxHeTe
        cAbpO+ylcR4WgocLn2zHuuLKJwKBgQDN4thrfKhrGCZ3aPfUrQRNJGKavNlbbvQf
        WJtZDjksxPCvX3EaD96e1+lpaJSMhsvK4u2qvXMLEq2t+TE7Puupz9ZlvIlcOsMI
        iJ6XO1yoDK2GgZsHyh5Ny6om/815JjUIdpeouGrBfqZZQ4ep6gB5nBg9Ov7w0qT7
        EpOH90KVkwKBgA+AmGKb6XYNDR+CREbRjX17I83kOsApJwHoEHWZrqR6H5hcnAIi
        DC7RbrgD/r+1FUH6jDhFr2TlCiTVZW5vFLzrZrknXgYrIibCcQcngitWDBgCLVOi
        1XSNSrooHVY6OLUAxfGFd+0ZXfki6y5EFq26ZSbfeAgKrZVZ1C2lICHRAoGBAICu
        FHfx3M26tWgSqjs5vCN+50YxXGSiT2A8IDQkCKYrnQbvTyBr5MdAyXkBTT8bjMoM
        1WDOsdWs4fKHeja+V8q1xRmnIe8MJxPxV7XL+1hpPBeCb+QJdrFG5t0jKkhbEfBt
        NtLUGJ1BTDUkWOlhANUBM8EpW2gnL8hgzua/KtWTAoGAZ5r9+C7UPcU3e8gYzERb
        tZUPPKcF6hUQa0CGUHIWzahH0hFSRILWLOlIu6I/pjHUoeGiZL2I1U2XmujIAAss
        yfW9vMbgEQZdJ4I5kzuI9mjA/7XZjrO7SPfKH92nFPfXC+3wwfocPj22ywBX/zig
        NSeBWga/Ot++ODQTXodbZ1k=
        -----END PRIVATE KEY-----
        PEM;

    public const string ROTATED_PUBLIC_KEY = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwc6kM/FlJzYzUhQ1/1uR
        fukxZknhJwIbb6Pn1/JfDw8FrnLgPF6Z9lgPJ0QYYNRQtOpQtuXjnzPHguUjq5j7
        SY7uascS6aGVoNc3jHtOiUzoxiqfPq8+jl7QpfMMn0P988pbANP31H26J5NWYQ3c
        TQWQutf2B3D/VWg7v5tmaqu4zm/48oJs9I9k5LYhmRB+BJbJSHV1tjA5hs+gQoQ4
        NvuRNbFY4bpNjjSwCrfleBMCRzC8qZZ6/3DmnGO8JjtJ2AqydD9YHwTxp+5jk2h0
        Lyi/SUqeDlFYxEIVCYffSEPOdtJP05IwPtTwssVDqtwIylSqPnRrayRzFbWLDvDH
        ZQIDAQAB
        -----END PUBLIC KEY-----
        PEM;

    public const string EDWARDS_PRIVATE_KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MC4CAQAwBQYDK2VwBCIEIHFqIkFWeXRMBrA9xKxKx28vs9/h7AzjMSUMRjum+lHO
        -----END PRIVATE KEY-----
        PEM;

    public const string EDWARDS_PUBLIC_KEY = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MCowBQYDK2VwAyEAemp2AHl2jCujoFp2LoEncybCNH5U3wlFxVyktnHlu+M=
        -----END PUBLIC KEY-----
        PEM;
}

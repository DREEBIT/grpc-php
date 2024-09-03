<?php

namespace Grpc;

interface ServiceInterface
{
    /**
     * Get the method descriptors of the service.
     * The array should have the form:
     *  ```[ <string serviceMethodPath> => MethodDescriptor ]```
     *
     * @return MethodDescriptor[]
     */
    public function getMethodDescriptors(): array;
}